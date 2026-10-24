<?php

namespace App\Services\Booking;

use App\Models\EventBookingGroup;
use App\Notifications\MemberNotification;
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
     * In-app notification to the member who booked; for guests it is kept on the
     * booking and shown once a member claims it (M08-D23). Never email or SMS here.
     */
    public function notify(EventBookingGroup $group, string $title, string $body): void
    {
        DB::afterCommit(function () use ($group, $title, $body): void {
            try {
                $notification = new MemberNotification(mb_substr($title, 0, 120), mb_substr($body, 0, 2000), 'booking', 'event', (string) $group->calendar_event_id);
                $booker = $group->booker()->first();
                if ($booker !== null) {
                    $this->notifier->send($booker, $notification->title, $notification->body, 'booking', 'event', (string) $group->calendar_event_id);
                } elseif ($group->is_guest) {
                    $group->notify($notification);
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
