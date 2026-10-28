<?php

namespace App\Services\Course;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One group is one capacity pool, independent of its individual calendar events.
 * Read-only preflight: never use this result as the final admission decision.
 */
final class CourseGroupReadiness
{
    public function inspect(int $groupId): array
    {
        $group = DB::table('course_groups')->find($groupId);
        if ($group === null) {
            throw ValidationException::withMessages(['group' => '課程集合不存在。']);
        }

        $events = DB::table('course_group_events as link')
            ->leftJoin('calendar_events as event', 'event.id', '=', 'link.calendar_event_id')
            ->where('link.course_group_id', $groupId)
            ->orderBy('link.position')
            ->get(['link.calendar_event_id', 'link.position', 'event.status',
                'event.booking_enabled', 'event.booking_capacity', 'event.starts_at']);

        $errors = [];
        if ($group->status !== 'open') {
            $errors[] = '集合尚未開放報名。';
        }
        if ((int) $group->expected_event_count < 1
            || $events->count() !== (int) $group->expected_event_count) {
            $errors[] = '尚未完成預定全部課堂。';
        }
        if ((int) $group->capacity < 1 || (float) $group->price_amount < 1) {
            $errors[] = '集合名額或收費設定無效。';
        }
        // Legacy standalone seats may predate group ownership. A group's
        // capacity is not safe unless each event has enough remaining seats
        // for the entire group. Do not silently overbook those events.
        $eventIds = $events->pluck('calendar_event_id')->all();
        $standaloneSeats = DB::table('event_booking_attendees as attendee')
            ->join('event_booking_groups as booking', 'booking.id', '=', 'attendee.event_booking_group_id')
            ->whereIn('attendee.calendar_event_id', $eventIds)
            ->where('attendee.active', 1)
            ->where('attendee.status', 'confirmed')
            ->where('booking.status', 'confirmed')
            // Coordinated group bookings are already accounted for by the
            // shared group capacity; only outside bookings consume extra seats.
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('invoice_booking_items as item')
                    ->whereColumn('item.event_booking_group_id', 'booking.id');
            })
            ->groupBy('attendee.calendar_event_id')
            ->selectRaw('attendee.calendar_event_id, COUNT(*) as seats')
            ->pluck('seats', 'attendee.calendar_event_id');
        // Positions must be a complete 1..N schedule. Count alone cannot
        // detect a missing first lesson or a shifted/incorrect sequence.
        $positions = $events->pluck('position')->map(static fn ($value): int => (int) $value)->all();
        if ($positions !== range(1, (int) max(1, $group->expected_event_count))) {
            $errors[] = '課堂順序必須從 1 起連續排列。';
        }

        // A member must have the entire cash-payment hold before lesson one.
        // Never accept a seat that will expire only after a lesson begins.
        $paymentDeadline = now()->addHours((int) $group->payment_hold_hours);
        if ((int) $group->payment_hold_hours < 1) {
            $errors[] = '付款保留時數必須大於零。';
        }
        foreach ($events as $event) {
            if ($event->status !== 'published' || ! $event->booking_enabled
                || (int) $event->booking_capacity < (int) $group->capacity
                    + (int) ($standaloneSeats[$event->calendar_event_id] ?? 0)
                || $event->starts_at === null || $event->starts_at <= $paymentDeadline) {
                $errors[] = '課堂 '.$event->position.' 未發布、已開始或名額不足。';
            }
        }

        return [
            'course_group_id' => $groupId,
            'expected_event_count' => (int) $group->expected_event_count,
            'configured_event_count' => $events->count(),
            'ready' => $errors === [],
            'errors' => $errors,
        ];
    }
}
