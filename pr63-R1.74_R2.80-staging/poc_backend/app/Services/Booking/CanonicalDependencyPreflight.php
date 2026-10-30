<?php

namespace App\Services\Booking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only preflight for retiring the legacy booking group/attendee ledger.
 *
 * No migration or writes occur here. A non-zero blocker count means the
 * historical mapping cannot be treated as verified.
 */
final class CanonicalDependencyPreflight
{
    /**
     * @return array{counts: array<string, int>, blockers: array<string, int>, ready: bool}
     */
    public function inspect(): array
    {
        $counts = [];
        $blockers = [];
        $requiredTables = [
            'event_bookings',
            'event_booking_groups',
            'event_booking_attendees',
            'event_attendances',
            'event_waitlist_entries',
            'course_makeups',
            'invoice_booking_items',
        ];

        $missingTables = [];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
                // Missing tables are never silently treated as empty data.
                $counts[$table] = 0;
                $blockers['missing_table_'.$table] = 1;

                continue;
            }

            $counts[$table] = DB::table($table)->count();
        }

        if ($missingTables !== []) {
            // The checks below assume the complete pre-cutover schema exists.
            return ['counts' => $counts, 'blockers' => $blockers, 'ready' => false];
        }

        // A partially deployed schema must fail closed before querying missing
        // columns. In particular, old S6 installations may lack the canonical
        // member/booker columns, and the bridge migration may not yet be applied.
        $requiredColumns = [
            'event_bookings' => ['calendar_event_id', 'member_user_id', 'booker_user_id', 'status', 'active'],
            'event_booking_groups' => ['id', 'calendar_event_id', 'legacy_booking_id', 'booker_user_id'],
            'event_booking_attendees' => ['id', 'calendar_event_id', 'event_booking_group_id', 'member_user_id'],
            'event_attendances' => ['event_booking_id'],
            'event_waitlist_entries' => ['event_booking_id'],
            'course_makeups' => ['event_booking_id'],
            'invoice_booking_items' => ['event_booking_id'],
        ];
        $missingColumns = false;
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $blockers['missing_column_'.$table.'_'.$column] = 1;
                    $missingColumns = true;
                }
            }
        }
        if ($missingColumns) {
            return ['counts' => $counts, 'blockers' => $blockers, 'ready' => false];
        }

        $blockers['canonical_missing_member'] = DB::table('event_bookings')
            ->whereNull('member_user_id')->count();
        $blockers['canonical_missing_booker'] = DB::table('event_bookings')
            ->whereNull('booker_user_id')->count();
        $blockers['canonical_active_state_mismatch'] = DB::table('event_bookings')
            ->whereIn('status', ['confirmed', 'waitlisted'])
            ->where(function ($q): void {
                $q->whereNull('active')->orWhere('active', '!=', 1);
            })->count();
        $blockers['canonical_cancelled_active_state_mismatch'] = DB::table('event_bookings')
            ->whereIn('status', ['cancelled', 'event_cancelled'])
            ->whereNotNull('active')->count();
        $blockers['canonical_duplicate_active_member'] = DB::table('event_bookings')
            ->where('active', 1)
            ->whereNotNull('member_user_id')
            ->select('calendar_event_id', 'member_user_id')
            ->groupBy('calendar_event_id', 'member_user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()->count();

        if (Schema::hasTable('event_booking_groups')) {
            $blockers['legacy_groups_without_canonical'] = DB::table('event_booking_groups')
                ->whereNull('legacy_booking_id')->count();
            $blockers['legacy_groups_missing_canonical_row'] = DB::table('event_booking_groups as g')
                ->leftJoin('event_bookings as b', 'b.id', '=', 'g.legacy_booking_id')
                ->whereNotNull('g.legacy_booking_id')
                ->whereNull('b.id')->count();
            $blockers['legacy_groups_event_mismatch'] = DB::table('event_booking_groups as g')
                ->join('event_bookings as b', 'b.id', '=', 'g.legacy_booking_id')
                ->whereColumn('g.calendar_event_id', '!=', 'b.calendar_event_id')->count();
            // A historical group can contain multiple people; mapping the whole
            // group to one booking would silently lose all but one seat.
            $blockers['legacy_groups_multiple_attendees'] = DB::table('event_booking_attendees')
                ->select('event_booking_group_id')
                ->groupBy('event_booking_group_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()->count();
            $blockers['legacy_groups_without_attendee'] = DB::table('event_booking_groups as g')
                ->leftJoin('event_booking_attendees as a', 'a.event_booking_group_id', '=', 'g.id')
                ->whereNull('a.id')->count();
        }

        if (Schema::hasTable('event_booking_attendees') && Schema::hasTable('event_booking_groups')) {
            $blockers['legacy_attendees_missing_member'] = DB::table('event_booking_attendees')
                ->whereNull('member_user_id')->count();
            $blockers['legacy_attendees_missing_group'] = DB::table('event_booking_attendees as a')
                ->leftJoin('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                ->whereNull('g.id')->count();
            $blockers['legacy_attendees_event_mismatch'] = DB::table('event_booking_attendees as a')
                ->join('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                ->whereColumn('a.calendar_event_id', '!=', 'g.calendar_event_id')->count();
            $blockers['legacy_attendees_member_mismatch'] = DB::table('event_booking_attendees as a')
                ->join('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                ->join('event_bookings as b', 'b.id', '=', 'g.legacy_booking_id')
                ->where(function ($q): void {
                    $q->whereNull('a.member_user_id')
                        ->orWhereNull('b.member_user_id')
                        ->orWhereColumn('a.member_user_id', '!=', 'b.member_user_id');
                })->count();
            $blockers['legacy_attendees_booking_event_mismatch'] = DB::table('event_booking_attendees as a')
                ->join('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                ->join('event_bookings as b', 'b.id', '=', 'g.legacy_booking_id')
                ->whereColumn('a.calendar_event_id', '!=', 'b.calendar_event_id')->count();
        }

        foreach ([
            'event_attendances' => 'event_booking_attendee_id',
            'event_waitlist_entries' => 'event_booking_group_id',
            'course_makeups' => 'event_booking_group_id',
            'invoice_booking_items' => 'event_booking_group_id',
        ] as $table => $legacyColumn) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'event_booking_id')) {
                $blockers[$table.'_missing_booking_fk'] = 1;
                continue;
            }
            // The old FK may still exist during the verified cutover phase.
            // Its removal is a separate migration gate, not a data error.
            $blockers[$table.'_unmapped'] = DB::table($table)
                ->whereNull('event_booking_id')->count();
            $blockers[$table.'_orphan_booking'] = DB::table($table.' as d')
                ->leftJoin('event_bookings as b', 'b.id', '=', 'd.event_booking_id')
                ->whereNotNull('d.event_booking_id')
                ->whereNull('b.id')->count();
            if (Schema::hasColumn($table, 'calendar_event_id')) {
                $blockers[$table.'_event_mismatch'] = DB::table($table.' as d')
                    ->join('event_bookings as b', 'b.id', '=', 'd.event_booking_id')
                    ->whereColumn('d.calendar_event_id', '!=', 'b.calendar_event_id')->count();
            }
        }

        return [
            'counts' => $counts,
            'blockers' => $blockers,
            'ready' => ! collect($blockers)->contains(fn (int $value): bool => $value > 0),
        ];
    }
}
