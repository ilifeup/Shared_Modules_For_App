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
        $rows = DB::table('event_attendances as attendance')
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
            ])->get();

        $report = [
            'total' => $rows->count(),
            'unmapped' => 0,
            'missing_legacy_attendee' => 0,
            'missing_booking_group' => 0,
            'missing_canonical_booking' => 0,
            'event_mismatch' => 0,
            'member_mismatch' => 0,
            'assigned_booking_mismatch' => 0,
            'duplicate_canonical_target' => 0,
            'safe_candidates' => 0,
        ];
        $targets = [];

        foreach ($rows as $row) {
            if ($row->assigned_booking_id === null) {
                $report['unmapped']++;
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
            if ((int) $row->attendee_member_id !== (int) $row->booking_member_id) {
                $report['member_mismatch']++;
                continue;
            }
            if ($row->assigned_booking_id !== null && (int) $row->assigned_booking_id !== (int) $row->booking_id) {
                $report['assigned_booking_mismatch']++;
                continue;
            }
            $target = (int) $row->booking_id;
            if (isset($targets[$target])) {
                $report['duplicate_canonical_target']++;
                continue;
            }
            $targets[$target] = true;
            if ($row->assigned_booking_id === null) {
                $report['safe_candidates']++;
            }
        }

        return $report;
    }
}
