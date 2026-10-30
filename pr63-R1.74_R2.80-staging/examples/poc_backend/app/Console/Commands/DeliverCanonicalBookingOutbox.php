<?php

namespace App\Console\Commands;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\MemberNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Deliver durable in-app booking notifications; Google sync is a separate cutover gate. */
final class DeliverCanonicalBookingOutbox extends Command
{
    protected $signature = 'booking:deliver-canonical-outbox {--limit=100 : Maximum events to process} {--retry-failed= : Reset one failed event ID for a subsequent delivery run} {--dry-run : Inspect pending events without delivering or modifying data}';
    protected $description = 'Deliver canonical booking outbox in-app notifications with retry-safe IDs';

    public function handle(MemberNotifier $notifier): int
    {
        if (! config('booking.canonical_outbox_delivery_ready', false)) {
            $this->error('Canonical outbox delivery is disabled.');
            return self::FAILURE;
        }
        $retryFailed = $this->option('retry-failed');
        if ($this->option('dry-run') && $retryFailed !== null) {
            $this->error('--dry-run cannot be combined with --retry-failed.');
            return self::FAILURE;
        }
        // Keep failed-event resets behind a separate operator approval gate.
        if ($retryFailed !== null && ! config('booking.canonical_outbox_manual_retry_ready', false)) {
            $this->error('Manual retry of failed outbox events is disabled.');
            return self::FAILURE;
        }
        if ($retryFailed !== null) {
            $retryId = filter_var($retryFailed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($retryId === false) {
                $this->error('--retry-failed must be a positive integer event ID.');
                return self::FAILURE;
            }
            // Revalidate the booking while holding the outbox row lock. A failed
            // event may have become obsolete since its last delivery attempt.
            $reset = DB::transaction(function () use ($retryId): bool {
                $event = DB::table('canonical_booking_outbox')
                    ->where('id', $retryId)->lockForUpdate()->first();
                if ($event === null || $event->state !== 'failed') {
                    return false;
                }
                $booking = EventBooking::query()->whereKey($event->event_booking_id)->lockForUpdate()->first();
                if ($booking === null
                    || (int) $booking->calendar_event_id !== (int) $event->calendar_event_id
                    || ($booking->status !== $event->transition
                        && ! ($event->transition === 'event_cancelled'
                            && $booking->status === EventBooking::STATUS_CANCELLED))) {
                    return false;
                }
                return DB::table('canonical_booking_outbox')
                    ->where('id', $retryId)->where('state', 'failed')->update([
                        'state' => 'pending',
                        'attempts' => 0,
                        'available_at' => now(),
                        'last_error' => null,
                        'updated_at' => now(),
                    ]) === 1;
            }, 3);
            if (! $reset) {
                $this->error('Event is missing, not failed, or no longer matches the booking state; nothing reset.');
                return self::FAILURE;
            }
            $this->info('Failed event reset to pending. Run the command again to deliver it.');
            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('--limit must be between 1 and 1000.');
            return self::FAILURE;
        }

        $ids = DB::table('canonical_booking_outbox')
            ->where('state', 'pending')->where('available_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id');
        if ($this->option('dry-run')) {
            $this->info('Pending outbox events eligible for delivery: ' . $ids->count());
            foreach ($ids as $id) {
                $this->line('Event ID: ' . $id);
            }
            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $notifier): void {
                    $event = DB::table('canonical_booking_outbox')->where('id', $id)
                        ->lockForUpdate()->first();
                    if ($event === null || $event->state !== 'pending'
                        || $event->available_at > now()) {
                        return;
                    }
                    $booking = EventBooking::query()->whereKey($event->event_booking_id)->lockForUpdate()->first();
                    if ($booking === null || (int) $booking->calendar_event_id !== (int) $event->calendar_event_id) {
                        throw new \RuntimeException('Outbox booking reference missing or inconsistent.');
                    }
                    // Do not send a stale transition after another booking change.
                    if ($booking->status !== $event->transition
                        && ! ($event->transition === 'event_cancelled' && $booking->status === EventBooking::STATUS_CANCELLED)) {
                        DB::table('canonical_booking_outbox')->where('id', $id)->update([
                            'state' => 'superseded', 'updated_at' => now(),
                        ]);
                        return;
                    }
                    $title = match ($event->transition) {
                        'confirmed' => '活動報名成功',
                        'waitlisted' => '活動候補登記成功',
                        'cancelled' => '活動預約已取消',
                        'event_cancelled' => '活動已取消',
                        default => throw new \RuntimeException('Unsupported outbox transition.'),
                    };
                    $recipients = array_unique(array_filter([
                        (int) $booking->booker_user_id,
                        (int) $booking->member_user_id,
                    ]));
                    foreach ($recipients as $recipientId) {
                        $user = User::query()->find($recipientId);
                        if ($user === null) {
                            throw new \RuntimeException('Outbox recipient missing.');
                        }
                        $deliveryId = (string) Str::uuid5(Str::NAMESPACE_URL,
                            $event->event_key . ':recipient:' . $recipientId);
                        if (! DB::table('notifications')->where('id', $deliveryId)->exists()) {
                            $notifier->send($user, $title, $title, 'booking', 'event',
                                (string) $booking->calendar_event_id, null, $deliveryId);
                        }
                    }
                    DB::table('canonical_booking_outbox')->where('id', $id)->update([
                        'state' => 'delivered', 'delivered_at' => now(),
                        'last_error' => null, 'updated_at' => now(),
                    ]);
                }, 3);
            } catch (\Throwable $error) {
                $failed++;
                DB::table('canonical_booking_outbox')->where('id', $id)
                    ->where('state', 'pending')->update([
                        'attempts' => DB::raw('attempts + 1'),
                        // Stop automatic retries after ten failures; preserve the event for operator review.
                        'state' => DB::raw("CASE WHEN attempts + 1 >= 10 THEN 'failed' ELSE 'pending' END"),
                        'available_at' => now()->addMinutes(5),
                        'last_error' => mb_substr($error->getMessage(), 0, 1000),
                        'updated_at' => now(),
                    ]);
                $this->warn('Outbox event ' . $id . ' deferred for retry.');
            }
        }
        $this->info('Outbox scanned: ' . $ids->count() . '; deferred: ' . $failed);
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
