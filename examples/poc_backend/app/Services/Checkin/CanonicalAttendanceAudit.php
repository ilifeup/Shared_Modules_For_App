<?php

namespace App\Services\Checkin;

use Illuminate\Support\Facades\DB;

/**
 * Read-only preflight for mapping legacy check-ins to canonical single-member
 * bookings. Never backfills or changes attendance data.
 */
final class CanonicalAttendanceAudit
{
    /** @return array<string, int> */
    public function inspect(): array
    {
        $query = DB::table('event_attendances as attendance')
            ->leftJoin('event_booking_attendees as attendee', 'attendee.id', '=', 'attendance.event_booking_attendee_id')
            ->leftJoin('event_booking_groups as booking_group', 'booking_group.id', '=', 'attendee.event_booking_group_id')
            ->leftJoin('event_bookings as booking', 'booking.id', '=', 'booking_group.legacy_booking_id')
            ->select([
                'attendance.id as attendance_id',
                'attendance.calendar_event_id as attendance_event_id',
                'attendance.event_booking_id as assigned_booking_id',
                'attendee.id as attendee_id',
                'attendee.calendar_event_id as attendee_event_id',
                'attendee.member_user_id as attendee_member_id',
                'booking_group.id as group_id',
                'booking.id as booking_id',
                'booking.calendar_event_id as booking_event_id',
                'booking.member_user_id as booking_member_id',
            ]);

        $report = [
            'total' => 0,
            'unmapped' => 0,
            'canonical_only' => 0,
            'missing_legacy_attendee' => 0,
            'missing_booking_group' => 0,
            'missing_canonical_booking' => 0,
            'event_mismatch' => 0,
            'member_mismatch' => 0,
            'assigned_booking_mismatch' => 0,
            'duplicate_canonical_target' => 0,
            'safe_candidates' => 0,
        ];
        // Count every candidate target first: the first of two conflicting rows
        // must not be reported as safe merely because it was visited first.
        $targetCounts = [];
        foreach ((clone $query)->orderBy('attendance.id')->cursor() as $row) {
            if ($row->booking_id !== null) {
                $id = (int) $row->booking_id;
                $targetCounts[$id] = ($targetCounts[$id] ?? 0) + 1;
            }
        }

        foreach ((clone $query)->orderBy('attendance.id')->cursor() as $row) {
            $report['total']++;
            if ($row->assigned_booking_id === null) {
                $report['unmapped']++;
            }
            if ($row->attendee_id === null && $row->assigned_booking_id !== null) {
                // Canonical-only attendance has no legacy attendee by design.
                // Do not misclassify it as a failed legacy backfill.
                $report['canonical_only']++;

                continue;
            }
            if ($row->attendee_id === null) {
                $report['missing_legacy_attendee']++;

                continue;
            }
            if ($row->group_id === null) {
                $report['missing_booking_group']++;

                continue;
            }
            if ($row->booking_id === null) {
                $report['missing_canonical_booking']++;

                continue;
            }
            if ((int) $row->attendance_event_id !== (int) $row->attendee_event_id
                || (int) $row->attendance_event_id !== (int) $row->booking_event_id) {
                $report['event_mismatch']++;

                continue;
            }
            // NULL is never a valid registered member. Casting both NULLs to
            // integer zero would otherwise incorrectly mark the row as safe.
            if ($row->attendee_member_id === null || $row->booking_member_id === null
                || (int) $row->attendee_member_id !== (int) $row->booking_member_id) {
                $report['member_mismatch']++;

                continue;
            }
            if ($row->assigned_booking_id !== null && (int) $row->assigned_booking_id !== (int) $row->booking_id) {
                $report['assigned_booking_mismatch']++;

                continue;
            }
            $target = (int) $row->booking_id;
            if ($targetCounts[$target] > 1) {
                $report['duplicate_canonical_target']++;

                continue;
            }
            if ($row->assigned_booking_id === null) {
                $report['safe_candidates']++;
            }
        }

        return $report;
    }
}
