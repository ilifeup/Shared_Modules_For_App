<?php

namespace App\Services\Booking;

use App\Models\EventBookingGroup;
use App\Services\Calendar\GoogleCalendarSync;
use App\Services\MemberNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifications and Google sync after a booking change. They run after the
 * database commit and never undo the booking (S9-INT-D11, L03 A).
 */
class BookingSideEffects
{
    public function __construct(
        private readonly MemberNotifier $notifier,
        private readonly GoogleCalendarSync $google,
    ) {}

    /**
     * In-app notification to the registered member who booked.
     * Never email or SMS here.
     */
    public function notify(EventBookingGroup $group, string $title, string $body): void
    {
        DB::afterCommit(function () use ($group, $title, $body): void {
            try {
                $booker = $group->booker()->first();
                if ($booker !== null) {
                    $this->notifier->send($booker, mb_substr($title, 0, 120), mb_substr($body, 0, 2000), 'booking', 'event', (string) $group->calendar_event_id);
                }
            } catch (Throwable $e) {
                Log::warning('booking notification failed', ['group_id' => $group->id, 'error' => class_basename($e)]);
            }
        });
    }

    public function syncBooking(int $userId, int $eventId): void
    {
        DB::afterCommit(function () use ($userId, $eventId): void {
            try {
                $this->google->queueBooking($userId, $eventId);
            } catch (Throwable $e) {
                Log::warning('google sync queue failed', ['user_id' => $userId, 'event_id' => $eventId, 'error' => class_basename($e)]);
            }
        });
    }
}
