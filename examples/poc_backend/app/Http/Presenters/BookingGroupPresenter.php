<?php

namespace App\Http\Presenters;

use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventWaitlistEntry;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use Illuminate\Support\Facades\DB;

/**
 * S9 M08 JSON of a booking group (separate from the S6 Booking DTO).
 */
final class BookingGroupPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(EventBookingGroup $group, ?User $viewer): array
    {
        $group->loadMissing('event', 'attendees.attendance', 'waitlistEntry');
        $event = $group->event;
        $position = null;
        if ($group->status === EventBookingGroup::STATUS_WAITLISTED && $group->waitlistEntry !== null) {
            $position = EventWaitlistEntry::query()->where('calendar_event_id', $group->calendar_event_id)
                ->where('status', EventWaitlistEntry::WAITING)->where('sequence', '<=', $group->waitlistEntry->sequence)->count();
        }
        $cancelUntil = $event->cancelClosesAt();
        // A derived occurrence of a series (term) is cancelled only through the
        // whole-series cancellation; the engine refuses it, so never offer it.
        $seriesOwned = DB::table('course_sessions')->where('calendar_event_id', $group->calendar_event_id)->exists()
            || DB::table('course_group_events')->where('calendar_event_id', $group->calendar_event_id)->exists();
        $canCancel = ! $seriesOwned && $group->isActive()
            && ($group->status === EventBookingGroup::STATUS_WAITLISTED || now()->lt($cancelUntil))
            && $viewer !== null
            && (int) $group->booker_user_id === (int) $viewer->id
            && $group->attendees->isNotEmpty()
            && $group->attendees->every(function (EventBookingAttendee $attendee) use ($viewer): bool {
                $member = $attendee->member_user_id !== null
                    ? User::query()->find($attendee->member_user_id)
                    : null;

                return $member !== null && app(BookingEngine::class)->canManageFamily($viewer, $member);
            });

        return [
            'id' => (string) $group->id,
            'event_id' => (string) $group->calendar_event_id,
            'status' => $group->status,
            'seat_count' => $group->seat_count,
            'contact_name' => $group->contact_name,
            'booked_at' => $group->booked_at->toIso8601ZuluString(),
            'confirmed_at' => $group->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $group->cancelled_at?->toIso8601ZuluString(),
            'waitlist_position' => $position,
            'cancel_deadline' => $cancelUntil->toIso8601ZuluString(),
            // Waitlist entries can always be withdrawn (D15); confirmed ones until the deadline.
            'can_cancel' => $canCancel,
            'attendees' => $group->attendees->map(fn (EventBookingAttendee $a): array => [
                'id' => (string) $a->id,
                'name' => $a->name,
                'status' => $a->status,
                'is_me' => $viewer !== null && $a->member_user_id === $viewer->id,
                'checkin_status' => $a->attendance?->status,
            ])->values()->all(),
            'event' => [
                'id' => (string) $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601ZuluString(),
                'ends_at' => $event->ends_at->toIso8601ZuluString(),
                'location' => $event->location,
                'floor' => $event->floor,
                'studio' => $event->studio,
                'status' => $event->status,
            ],
        ];
    }
}
