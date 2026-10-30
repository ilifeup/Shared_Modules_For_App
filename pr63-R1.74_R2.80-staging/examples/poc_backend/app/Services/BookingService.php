<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\CanonicalBookingWriter;
use App\Services\Booking\CanonicalBookingCancellation;
use App\Support\Api\ApiException;

/**
 * Compatibility boundary for S6 booking endpoints.
 * Canonical mode is intentionally OFF until notification, calendar and
 * financial consumers are cut over and database integration tests pass.
 */
final class BookingService
{
    public function __construct(
        private readonly BookingEngine $engine,
        private readonly CanonicalBookingWriter $canonicalWriter,
        private readonly CanonicalBookingCancellation $canonicalCancellation
    ) {}

    private function canonicalEnabled(): bool
    {
        return config('booking.canonical_s6_cutover_ready', false) === true;
    }

    public function unavailableReason(CalendarEvent $event, ?int $confirmed = null): ?string
    {
        if (! $this->canonicalEnabled()) {
            return $this->engine->unavailableReason($event);
        }

        // Canonical S6 has no waitlist entry point. A waiting member always
        // owns the next available seat, even if the event has spare capacity.
        if ($event->status === CalendarEvent::STATUS_CANCELLED) {
            return 'event_cancelled';
        }
        if ($event->status !== CalendarEvent::STATUS_PUBLISHED) {
            return 'event_not_published';
        }
        if (! $event->booking_enabled) {
            return 'disabled';
        }
        if ($event->booking_opens_at !== null && now()->lt($event->booking_opens_at)) {
            return 'not_open';
        }
        if (now()->gte($event->bookingClosesAt())) {
            return 'closed';
        }
        if ($this->engine->waitingGroups($event) > 0) {
            return 'full';
        }
        if ($event->booking_capacity !== null
            && ($confirmed ?? $this->confirmedCount($event)) >= (int) $event->booking_capacity) {
            return 'full';
        }

        return null;
    }

    public function confirmedCount(CalendarEvent $event): int
    {
        return $this->engine->confirmedSeats($event);
    }

    public function activeBookingOf(CalendarEvent $event, User $member): ?EventBooking
    {
        if ($this->canonicalEnabled()) {
            return $this->engine->activeAttendeeOf($event, $member);
        }

        return $event->bookings()->where('user_id', $member->id)
            ->where('status', EventBooking::STATUS_CONFIRMED)->first();
    }

    public function hasActivePlace(CalendarEvent $event, User $member): bool
    {
        return $this->engine->activeAttendeeOf($event, $member) !== null;
    }

    public function book(CalendarEvent $event, User $member): EventBooking
    {
        if ($this->canonicalEnabled()) {
            // The S6 endpoint has no idempotency key and only books the caller.
            // No legacy group/attendee ledger may be written on this path.
            return $this->canonicalWriter->reserve($event, $member, $member, null, false);
        }

        return $this->engine->bookLegacy($event, $member);
    }

    public function cancel(EventBooking $booking, User $actor): EventBooking
    {
        // A stale HTTP actor must not confer admin rights; both engines
        // revalidate the current identity under their own transaction.
        $byAdmin = $actor->is_active && ! $actor->FamilyUser && $actor->isAdmin();
        if ($this->canonicalEnabled()) {
            return $this->canonicalCancellation->cancel($booking, $actor, $byAdmin);
        }

        return $this->engine->cancelLegacy($booking, $actor, $byAdmin);
    }

    public static function unavailable(string $reason): ApiException
    {
        return BookingEngine::unavailable($reason);
    }
}
