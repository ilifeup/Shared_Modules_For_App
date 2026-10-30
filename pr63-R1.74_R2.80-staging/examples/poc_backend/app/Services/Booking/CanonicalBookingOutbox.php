<?php

namespace App\Services\Booking;

use App\Models\EventBooking;
use Illuminate\Support\Facades\DB;

/** Transactional, durable record of a canonical booking state transition.
 * This records intent only; a separate dispatcher/consumer is required before
 * enabling notifications or calendar synchronization in production.
 */
final class CanonicalBookingOutbox
{
    public function record(EventBooking $booking, string $transition): void
    {
        if (! DB::transactionLevel()) {
            throw new \LogicException('Canonical outbox must be written inside the booking transaction.');
        }
        if (! in_array($transition, ['confirmed', 'waitlisted', 'cancelled', 'event_cancelled'], true)) {
            throw new \InvalidArgumentException('Unsupported booking transition.');
        }
        // A booking can be confirmed after waitlisting; each distinct state
        // transition has its own event key. Repeated attempts are idempotent.
        // A missing migration must abort the booking transaction rather than
        // silently losing an event. Unique event_key handles retries.
        DB::table('canonical_booking_outbox')->insertOrIgnore([
            'event_key' => 'booking:' . $booking->id . ':' . $transition,
            'event_booking_id' => $booking->id,
            'calendar_event_id' => $booking->calendar_event_id,
            'transition' => $transition,
            'state' => 'pending',
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
