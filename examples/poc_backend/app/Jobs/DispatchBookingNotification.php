<?php

namespace App\Jobs;

use App\Models\EventBookingGroup;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Durable booking notification handoff. The caller must enqueue this job
 * inside the same database transaction as the booking state change.
 * This job does not send email or SMS.
 */
class DispatchBookingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public readonly string $deliveryId;

    public function __construct(
        public readonly int $groupId,
        public readonly string $title,
        public readonly string $body,
    ) {
        $this->deliveryId = (string) Str::uuid();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MemberNotifier $notifier): void
    {
        DB::transaction(function () use ($notifier): void {
            // Serialize retries for this booking group, including concurrent workers.
            $group = EventBookingGroup::query()->lockForUpdate()->find($this->groupId);
            if ($group === null) {
                Log::warning('booking notification skipped: group missing', [
                    'group_id' => $this->groupId,
                    'delivery_id' => $this->deliveryId,
                ]);

                return;
            }

            if (DB::table('notifications')->where('id', $this->deliveryId)->exists()) {
                return;
            }

            $title = trim(mb_substr($this->title, 0, 120));
            $body = trim(mb_substr($this->body, 0, 2000));
            // Match MemberNotifier validation for guests as well as members.
            if ($title === '' || $body === '') {
                throw new ApiException(422, 'VALIDATION_FAILED', '通知標題與內容不可為空。');
            }
            $booker = $group->booker()->first();

            if ($booker === null) {
                // Member-only schema forbids guest bookings. Never deliver to a
                // booking group as a fallback if its member relation is broken.
                Log::warning('booking notification skipped: member missing', [
                    'group_id' => $this->groupId,
                    'delivery_id' => $this->deliveryId,
                ]);

                return;
            }

            $notifier->send($booker, $title, $body, 'booking', 'event', (string) $group->calendar_event_id, null, $this->deliveryId);
        });
    }
}
