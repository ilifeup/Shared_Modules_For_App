<?php

namespace App\Services\Booking;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, per-occurrence cutover audit. Never migrates or deletes records. */
final class CanonicalCutoverAudit
{
    public function inspect(CalendarEvent $event): array
    {
        $id = (int) $event->id;
        $issues = [];
        $counts = [];

        // Legacy attendees normally reference a group, not the event directly.
        // A missing legacy table/foreign key is not evidence of a clean cutover.
        foreach (['event_booking_groups', 'event_booking_attendees', 'event_waitlist_entries'] as $table) {
            if (! Schema::hasTable($table)) {
                $counts[$table] = null;
                $issues[] = $table . ':missing_table';
                continue;
            }
            if ($table === 'event_booking_attendees'
                && ! Schema::hasColumn($table, 'calendar_event_id')) {
                if (! Schema::hasColumn($table, 'event_booking_group_id')
                    || ! Schema::hasTable('event_booking_groups')
                    || ! Schema::hasColumn('event_booking_groups', 'calendar_event_id')) {
                    $counts[$table] = null;
                    $issues[] = $table . ':missing_event_join';
                    continue;
                }
                $counts[$table] = DB::table('event_booking_attendees as a')
                    ->join('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                    ->where('g.calendar_event_id', $id)->count();
                // Orphaned attendees cannot safely be assigned to an event.
                if (DB::table('event_booking_attendees as a')
                    ->leftJoin('event_booking_groups as g', 'g.id', '=', 'a.event_booking_group_id')
                    ->whereNull('g.id')->exists()) {
                    $issues[] = 'orphaned_legacy_attendees';
                }
                continue;
            }
            if (! Schema::hasColumn($table, 'calendar_event_id')) {
                $counts[$table] = null;
                $issues[] = $table . ':missing_calendar_event_id';
                continue;
            }
            $counts[$table] = DB::table($table)->where('calendar_event_id', $id)->count();
        }

        if (($counts['event_booking_groups'] ?? 0) > 0) {
            $issues[] = 'legacy_booking_groups_pending';
        }
        if (($counts['event_booking_attendees'] ?? 0) > 0) {
            $issues[] = 'legacy_booking_attendees_pending';
        }
        // A canonical-linked waiting projection is expected and is not a legacy blocker.
        // Detect projection duplication and mismatch before any cutover decision.
        if (Schema::hasTable('event_waitlist_entries')
            && Schema::hasColumn('event_waitlist_entries', 'event_booking_id')) {
            $projections = DB::table('event_waitlist_entries')
                ->where('calendar_event_id', $id)->where('status', 'waiting');
            $counts['waiting_projections'] = (clone $projections)->count();
            if (DB::table('event_waitlist_entries')
                ->where('calendar_event_id', $id)->where('status', 'waiting')
                ->whereNotNull('event_booking_id')->select('event_booking_id')
                ->groupBy('event_booking_id')->havingRaw('COUNT(*) > 1')->exists()) {
                $issues[] = 'duplicate_waiting_projection';
            }
            // The projection schema calls this column `sequence`, not
            // `waitlist_sequence` (which belongs to event_bookings).
            if (! Schema::hasColumn('event_waitlist_entries', 'sequence')) {
                $issues[] = 'waiting_projection_missing_sequence_column';
            } else {
                if (DB::table('event_waitlist_entries as w')
                    ->join('event_bookings as b', 'b.id', '=', 'w.event_booking_id')
                    ->where('w.calendar_event_id', $id)->where('w.status', 'waiting')
                    ->whereColumn('b.calendar_event_id', 'w.calendar_event_id')
                    ->where('b.status', EventBooking::STATUS_WAITLISTED)
                    ->where('b.active', 1)
                    ->where(function ($q) {
                        $q->whereNull('w.sequence')
                            ->orWhereColumn('w.sequence', '!=', 'b.waitlist_sequence');
                    })->exists()) {
                    $issues[] = 'waiting_projection_sequence_mismatch';
                }
            }
            // Check ALL projection states, not only waiting ones. A stale
            // `promoted`/`cancelled` projection pointing at a live waitlisted
            // booking can hide a member from the legacy queue and corrupt
            // reconciliation even when the waiting-only checks pass.
            if (DB::table('event_waitlist_entries as w')
                ->join('event_bookings as b', 'b.id', '=', 'w.event_booking_id')
                ->where('w.calendar_event_id', $id)
                ->where('b.calendar_event_id', $id)
                ->where('b.active', 1)
                ->where('b.status', EventBooking::STATUS_WAITLISTED)
                ->where('w.status', '!=', 'waiting')->exists()) {
                $issues[] = 'live_waitlist_projection_not_waiting';
            }
            // One canonical booking must not have multiple projection rows
            // in different statuses; otherwise promotion/cancellation updates
            // can silently leave an old projection behind.
            if (DB::table('event_waitlist_entries')
                ->where('calendar_event_id', $id)
                ->whereNotNull('event_booking_id')
                ->select('event_booking_id')->groupBy('event_booking_id')
                ->havingRaw('COUNT(*) > 1')->exists()) {
                $issues[] = 'duplicate_canonical_waitlist_projection';
            }
            // A waiting projection must point to an active, waiting booking
            // for the same occurrence. Otherwise it can corrupt FIFO cutover.
            if (DB::table('event_waitlist_entries as w')
                ->leftJoin('event_bookings as b', 'b.id', '=', 'w.event_booking_id')
                ->where('w.calendar_event_id', $id)->where('w.status', 'waiting')
                ->where(function ($q) {
                    $q->whereNull('w.event_booking_id')->orWhereNull('b.id')
                        ->orWhereColumn('b.calendar_event_id', '!=', 'w.calendar_event_id')
                        ->orWhere('b.status', '!=', EventBooking::STATUS_WAITLISTED)
                        ->orWhereNull('b.active')->orWhere('b.active', '!=', 1);
                })->exists()) {
                $issues[] = 'waiting_projection_invalid_booking';
            }
        }
        if (app(CanonicalLegacyWaitlistBoundary::class)->hasUnmigratedWaitingEntries($id)) {
            $issues[] = 'legacy_waitlist_pending';
        }
        if (app(CanonicalSeriesOwnership::class)->ownsEvent($id)) {
            $issues[] = 'series_owned_occurrence';
        }
        if ((float) $event->price_amount > 0) {
            $issues[] = 'paid_lifecycle_not_cut_over';
        }
        $waiting = EventBooking::query()->where('calendar_event_id', $id)
            ->where('active', 1)->where('status', EventBooking::STATUS_WAITLISTED);
        $counts['canonical_confirmed'] = EventBooking::query()->where('calendar_event_id', $id)
            ->where('active', 1)->where('status', EventBooking::STATUS_CONFIRMED)->count();
        $counts['canonical_waiting'] = (clone $waiting)->count();
        if ((clone $waiting)->where(function ($q) {
            $q->whereNull('waitlist_sequence')->orWhere('waitlist_sequence', '<=', 0);
        })->exists()) {
            $issues[] = 'invalid_fifo_sequence';
        }
        if (EventBooking::query()->where('calendar_event_id', $id)->where('active', 1)
            ->where('status', EventBooking::STATUS_WAITLISTED)
            ->whereNotNull('waitlist_sequence')->select('waitlist_sequence')
            ->groupBy('waitlist_sequence')->havingRaw('COUNT(*) > 1')->exists()) {
            $issues[] = 'duplicate_fifo_sequence';
        }
        // Canonical ledger invariants must be checked independently of legacy rows.
        // A clean legacy count alone does not make an overbooked event safe to cut over.
        if (EventBooking::query()->where('calendar_event_id', $id)->where('active', 1)
            ->select('member_user_id')->groupBy('member_user_id')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            $issues[] = 'duplicate_active_member_booking';
        }
        if ($event->booking_capacity !== null
            && $counts['canonical_confirmed'] > (int) $event->booking_capacity) {
            $issues[] = 'canonical_capacity_exceeded';
        }
        if (EventBooking::query()->where('calendar_event_id', $id)->where('active', 1)
            ->whereNotIn('status', [EventBooking::STATUS_CONFIRMED, EventBooking::STATUS_WAITLISTED])
            ->exists()) {
            $issues[] = 'active_booking_invalid_status';
        }
        if (EventBooking::query()->where('calendar_event_id', $id)->where('active', 0)
            ->whereIn('status', [EventBooking::STATUS_CONFIRMED, EventBooking::STATUS_WAITLISTED])
            ->exists()) {
            $issues[] = 'inactive_booking_live_status';
        }
        return [
            'calendar_event_id' => $id,
            'ready_for_free_canonical_booking' => $issues === [],
            'issues' => $issues,
            'counts' => $counts,
            'note' => 'Read-only snapshot; a passing audit does not authorize deployment or paid cutover.',
        ];
    }
}
