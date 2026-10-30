<?php

namespace App\Services\Booking;

use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared cutover check. A waiting entry linked to an active canonical
 * waitlisted booking is not a legacy queue entry and must not block itself.
 */
final class CanonicalLegacyWaitlistBoundary
{
    public function hasUnmigratedWaitingEntries(int $eventId): bool
    {
        if (! Schema::hasTable('event_waitlist_entries')) {
            return false;
        }

        $waiting = DB::table('event_waitlist_entries')
            ->where('calendar_event_id', $eventId)->where('status', 'waiting');
        if (! $waiting->exists()) {
            return false;
        }
        if (! Schema::hasColumn('event_waitlist_entries', 'event_booking_id')) {
            return true;
        }

        return DB::table('event_waitlist_entries as w')
            ->leftJoin('event_bookings as b', 'b.id', '=', 'w.event_booking_id')
            ->where('w.calendar_event_id', $eventId)
            ->where('w.status', 'waiting')
            ->where(function ($query) {
                $query->whereNull('w.event_booking_id')
                    ->orWhereNull('b.id')
                    ->orWhereColumn('b.calendar_event_id', '!=', 'w.calendar_event_id')
                    ->orWhere('b.status', '!=', 'waitlisted')
                    ->orWhereNull('b.active')
                    ->orWhere('b.active', '!=', 1);
            })->exists();
    }
}
