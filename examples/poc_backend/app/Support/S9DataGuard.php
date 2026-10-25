<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * S9 rollback safety: rolling back the S9 migration drops its tables and the S9
 * columns of calendar_events, so every row created while S9 was live is lost.
 * Only data the migration itself can rebuild (the S6 booking backfill and the
 * seeded placement) is excluded from the count.
 *
 * The rollback refuses to run while such data exists unless the operator sets
 * S9_ROLLBACK_ACCEPT_DATA_LOSS=yes after taking the backup described in
 * docs/s9_technical_decisions.md §6. Read from the process environment on
 * purpose (not config/env()), so a cached config can never pre-approve it.
 */
final class S9DataGuard
{
    public const CONFIRM_VARIABLE = 'S9_ROLLBACK_ACCEPT_DATA_LOSS';

    /** Tables whose every row would be lost. */
    private const TABLES = [
        'verification_types', 'verification_requests', 'verification_evidence', 'verification_reviews', 'member_verifications',
        'event_categories', 'event_series', 'private_calendar_events', 'google_calendar_connections', 'google_calendar_event_mappings',
        'event_waitlist_entries', 'checkin_locations', 'checkin_location_events', 'event_attendances', 'event_attendance_corrections',
        'tags', 'taggables', 'bookmarks', 'link_clicks', 'link_daily_stats', 'marketing_campaigns', 'marketing_campaign_placement',
    ];

    /**
     * Rows that a rollback would delete and the migration could not rebuild.
     *
     * @return array<string, int> table (or label) => rows, only non-zero entries
     */
    public static function report(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        if (Schema::hasTable('event_booking_groups')) {
            // Groups not created by the S6 backfill: S9 group, guest and admin bookings.
            $counts['event_booking_groups (S9 bookings)'] = DB::table('event_booking_groups')->whereNull('legacy_booking_id')->count();
            $counts['event_booking_attendees (S9 bookings)'] = DB::table('event_booking_attendees')
                ->whereIn('event_booking_group_id', DB::table('event_booking_groups')->select('id')->whereNull('legacy_booking_id'))->count();
        }
        if (Schema::hasTable('marketing_placements')) {
            $counts['marketing_placements (added by admins)'] = DB::table('marketing_placements')->where('code', '!=', 'home_banner')->count();
        }
        if (Schema::hasColumn('calendar_events', 'event_series_id')) {
            $counts['calendar_events with S9 settings'] = DB::table('calendar_events')->where(fn ($q) => $q
                ->whereNotNull('event_series_id')->orWhereNotNull('event_category_id')->orWhereNotNull('floor')
                ->orWhereNotNull('studio')->orWhere('checkin_enabled', true)->orWhere('booking_max_seats', '>', 1)
                ->orWhereNotNull('booking_close_offset_minutes')->orWhereNotNull('cancel_closes_at')->orWhereNotNull('cancel_offset_minutes'))->count();
        }

        return array_filter($counts);
    }

    public static function accepted(): bool
    {
        return getenv(self::CONFIRM_VARIABLE) === 'yes';
    }

    /**
     * @throws RuntimeException when data would be lost and the operator has not accepted it
     */
    public static function assertRollbackAllowed(): void
    {
        $report = self::report();
        if ($report === [] || self::accepted()) {
            return;
        }

        $lines = implode(', ', array_map(fn (string $table, int $rows) => "{$table}={$rows}", array_keys($report), $report));

        throw new RuntimeException(
            'S9 rollback refused: it would permanently delete data created while S9 was live ('.$lines.'). '
            .'Back up the database first (docs/s9_technical_decisions.md §6), then re-run with '
            .self::CONFIRM_VARIABLE.'=yes. Prefer a forward fix over a rollback.'
        );
    }
}
