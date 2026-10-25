<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Support\Api\ApiException;

/**
 * S6 M08 single-member booking API, kept for compatibility. Since S9 every call goes
 * through BookingEngine (one seat ledger, one lock order, strict FIFO), so S6 and
 * S9 bookings can never double-count or overbook (S9-L06 A).
 */
final class BookingService
{
    public function __construct(private readonly BookingEngine $engine) {}

    /**
     * Why an event does not accept new bookings now, or null when it does.
     * One of: event_not_published, event_cancelled, disabled, not_open, closed, full.
     */
    public function unavailableReason(CalendarEvent $event, ?int $confirmed = null): ?string
    {
        return $this->engine->unavailableReason($event);
    }

    /** Confirmed seats (real attendees) — equals booking rows for single bookings. */
    public function confirmedCount(CalendarEvent $event): int
    {
        return $this->engine->confirmedSeats($event);
    }

    public function activeBookingOf(CalendarEvent $event, User $member): ?EventBooking
    {
        return $event->bookings()->where('user_id', $member->id)->where('status', EventBooking::STATUS_CONFIRMED)->first();
    }

    /** True when the member holds a place by any entry point (S6 or S9 group / waitlist). */
    public function hasActivePlace(CalendarEvent $event, User $member): bool
    {
        return $this->engine->activeAttendeeOf($event, $member) !== null;
    }

    public function book(CalendarEvent $event, User $member): EventBooking
    {
        return $this->engine->bookLegacy($event, $member);
    }

    /**
     * Cancels a confirmed booking (kept as history). $actor is the member or an admin.
     */
    public function cancel(EventBooking $booking, User $actor): EventBooking
    {
        return $this->engine->cancelLegacy($booking, $actor, $actor->id !== $booking->user_id);
    }

    public static function unavailable(string $reason): ApiException
    {
        return BookingEngine::unavailable($reason);
    }
}
