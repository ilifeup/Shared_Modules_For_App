<?php

namespace App\Services\Booking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolve ownership without requiring optional course modules to be installed. */
final class CanonicalSeriesOwnership
{
    public function ownsEvent(int $eventId): bool
    {
        foreach (['course_sessions', 'course_group_events'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            // A partially migrated course module must not silently grant
            // direct-booking access to a potentially course-owned event.
            if (! Schema::hasColumn($table, 'calendar_event_id')) {
                throw new \RuntimeException("Missing {$table}.calendar_event_id; course migration incomplete.");
            }
            if (DB::table($table)->where('calendar_event_id', $eventId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
