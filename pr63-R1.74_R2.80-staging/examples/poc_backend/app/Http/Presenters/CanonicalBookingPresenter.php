<?php

namespace App\Http\Presenters;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\CanonicalSeriesOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Canonical single-member DTO; never invents a legacy group or attendee ID. */
final class CanonicalBookingPresenter
{
    public static function present(EventBooking $booking, ?User $viewer): array
    {
        $booking->loadMissing('event', 'member', 'canonicalAttendance');
        $event = $booking->event;
        $position = null;
        if ($booking->status === EventBooking::STATUS_WAITLISTED) {
            $position = DB::table('event_bookings')
                ->where('calendar_event_id', $booking->calendar_event_id)
                ->where('status', EventBooking::STATUS_WAITLISTED)->where('active', 1)
                ->where('waitlist_sequence', '<=', $booking->waitlist_sequence)->count();
        }
        // A member can cancel their own seat even when the original family booker
        // no longer manages them. A former booker must not retain authority.
        $isMember = $viewer !== null && (int) $booking->member_user_id === (int) $viewer->id;
        $isBooker = $viewer !== null && (int) $booking->booker_user_id === (int) $viewer->id;
        // The member's own cancellation does not require family delegation.
        // BookingEngine::canManageFamily intentionally rejects FamilyUser as a
        // *manager*, so using it for self-cancellation would hide a valid action.
        // Match CanonicalBookingCancellation's current verified-actor policy.
        $verifiedActor = $viewer !== null && (bool) $viewer->is_active
            && (int) $viewer->verified_status === 2;
        $canManage = $verifiedActor && $booking->member !== null
            && ($isMember || ($isBooker
                && app(BookingEngine::class)->canManageFamily($viewer, $booking->member)));
        // Do not advertise cancellation where the canonical cancellation service
        // intentionally rejects paid bookings or unmigrated legacy dependencies.
        $legacyGroupExists = Schema::hasTable('event_booking_groups')
            && DB::table('event_booking_groups')->where('calendar_event_id', $event->id)->exists();
        $dependencyBlocked = false;
        foreach (['course_makeups', 'invoice_booking_items', 'event_attendances'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'event_booking_id')
                || DB::table($table)->where('event_booking_id', $booking->id)->exists()) {
                $dependencyBlocked = true;
                break;
            }
        }
        // Cancellation requires exactly one matching waiting projection. Do not
        // advertise a button when the transactional cancellation will fail.
        $projectionValid = true;
        if ($booking->status === EventBooking::STATUS_WAITLISTED) {
            $projectionValid = Schema::hasTable('event_waitlist_entries')
                && Schema::hasColumn('event_waitlist_entries', 'event_booking_id');
            if ($projectionValid) {
                $entries = DB::table('event_waitlist_entries')
                    ->where('event_booking_id', $booking->id)->limit(2)->get();
                $projectionValid = $entries->count() === 1
                    && (int) $entries->first()->calendar_event_id === (int) $event->id
                    && $entries->first()->status === 'waiting'
                    && (int) $entries->first()->sequence === (int) $booking->waitlist_sequence;
            }
        }
        $canCancel = $projectionValid && ! $legacyGroupExists && ! $dependencyBlocked
            && (float) $event->price_amount <= 0
            && $booking->isActive() && $canManage
            && $event->status !== 'cancelled'
            && ! app(CanonicalSeriesOwnership::class)->ownsEvent((int) $event->id)
            && ($booking->status === EventBooking::STATUS_WAITLISTED || now()->lt($event->cancelClosesAt()));

        return [
            'id' => (string) $booking->id,
            'event_id' => (string) $event->id,
            'status' => $booking->status,
            'seat_count' => 1,
            'contact_name' => $booking->member?->name,
            'booked_at' => $booking->booked_at?->toIso8601ZuluString(),
            'confirmed_at' => $booking->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $booking->cancelled_at?->toIso8601ZuluString(),
            'waitlist_position' => $position,
            'cancel_deadline' => $event->cancelClosesAt()->toIso8601ZuluString(),
            'can_cancel' => $canCancel,
            'attendees' => [[
                'id' => (string) $booking->member_user_id,
                'name' => $booking->member?->name,
                'status' => $booking->status,
                'is_me' => $viewer !== null && (int) $booking->member_user_id === (int) $viewer->id,
                'checkin_status' => $booking->canonicalAttendance?->status,
            ]],
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
