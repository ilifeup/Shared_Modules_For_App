<?php

namespace App\Jobs;

use App\Models\EventBooking;
use App\Services\MemberNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Canonical booking notification: never looks up the removed group ledger. */
final class DispatchCanonicalBookingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;
    public readonly string $deliveryId;

    public function __construct(public readonly int $bookingId, public readonly string $transition)
    {
        $this->deliveryId = (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MemberNotifier $notifier): void
    {
        DB::transaction(function () use ($notifier): void {
            $booking = EventBooking::query()->lockForUpdate()->find($this->bookingId);
            if ($booking === null || ! in_array($this->transition, ['confirmed', 'waitlisted', 'cancelled'], true)) {
                return;
            }
            if (DB::table('notifications')->where('id', $this->deliveryId)->exists()) {
                return;
            }
            // An obsolete queued transition must not send a misleading notice.
            if ($booking->status !== $this->transition) {
                return;
            }
            $title = match ($this->transition) {
                'confirmed' => '活動報名成功',
                'waitlisted' => '活動候補登記成功',
                'cancelled' => '活動預約已取消',
            };
            // The booker and the actual participant may be different verified
            // members. Notify both, without sending duplicate notices for self
            // bookings. Each recipient has a stable, retry-safe delivery ID.
            $recipients = array_unique(array_filter([
                (int) $booking->booker_user_id,
                (int) $booking->member_user_id,
            ]));
            sort($recipients);
            foreach ($recipients as $recipientId) {
                $recipient = \App\Models\User::query()->find($recipientId);
                if ($recipient === null) {
                    continue;
                }
                // UUID v5 is deterministic for this job's delivery and member.
                // Never reuse the same notifications.id for two recipients.
                $recipientDeliveryId = (string) Str::uuid5(
                    Str::NAMESPACE_URL,
                    $this->deliveryId . ':recipient:' . $recipientId
                );
                if (DB::table('notifications')->where('id', $recipientDeliveryId)->exists()) {
                    continue;
                }
                $notifier->send($recipient, $title, $title, 'booking', 'event',
                    (string) $booking->calendar_event_id, null, $recipientDeliveryId);
            }
        });
    }
}
