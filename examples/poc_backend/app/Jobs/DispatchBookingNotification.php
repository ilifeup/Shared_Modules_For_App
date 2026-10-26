<?php

namespace App\Jobs;

use App\Models\EventBookingGroup;
use App\Notifications\MemberNotification;
use App\Services\MemberNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Durable booking notification handoff. The caller must enqueue this job
 * inside the same database transaction as the booking state change.
 * This job does not send email or SMS.
 */
class DispatchBookingNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $groupId,
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(MemberNotifier $notifier): void
    {
        $group = EventBookingGroup::query()->find($this->groupId);
        if ($group === null) {
            return;
        }

        $title = mb_substr($this->title, 0, 120);
        $body = mb_substr($this->body, 0, 2000);
        $booker = $group->booker()->first();

        if ($booker !== null) {
            $notifier->send($booker, $title, $body, 'booking', 'event', (string) $group->calendar_event_id);
        } elseif ($group->is_guest) {
            $group->notify(new MemberNotification($title, $body, 'booking', 'event', (string) $group->calendar_event_id));
        }
    }
}
