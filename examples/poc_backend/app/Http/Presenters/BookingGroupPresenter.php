<?php

namespace App\Http\Presenters;

use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventWaitlistEntry;
use App\Models\User;
use App\Services\Family\FamilyDelegation;

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
        $canCancel = $group->isActive()
            && ($group->status === EventBookingGroup::STATUS_WAITLISTED || now()->lt($cancelUntil))
            && $viewer !== null
            && (int) $group->booker_user_id === (int) $viewer->id
            && app(FamilyDelegation::class)->canManageMemberIds(
                $viewer,
                $group->attendees->pluck('member_user_id')->all()
            );

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
