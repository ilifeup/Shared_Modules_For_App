<?php

use App\Services\Checkin\CanonicalAttendanceAudit;
use Illuminate\Support\Facades\DB;

function auditRow(int $attendanceId, ?int $bookingId, array $overrides = []): object
{
    return (object) array_replace([
        'attendance_id' => $attendanceId,
        'attendance_event_id' => 10,
        'assigned_booking_id' => null,
        'attendee_id' => $attendanceId + 100,
        'attendee_event_id' => 10,
        'attendee_member_id' => 20,
        'group_id' => $attendanceId + 200,
        'booking_id' => $bookingId,
        'booking_event_id' => 10,
        'booking_member_id' => 20,
    ], $overrides);
}

function inspectAuditRows(array $rows): array
{
    $builder = Mockery::mock();
    $builder->shouldReceive('leftJoin')->times(3)->andReturnSelf();
    $builder->shouldReceive('select')->once()->andReturnSelf();
    $builder->shouldReceive('get')->once()->andReturn(collect($rows));
    DB::shouldReceive('table')->once()->with('event_attendances as attendance')->andReturn($builder);

    return app(CanonicalAttendanceAudit::class)->inspect();
}

it('marks a valid one-to-one legacy attendance as a safe canonical candidate', function () {
    $report = inspectAuditRows([auditRow(1, 301)]);

    expect($report['total'])->toBe(1)
        ->and($report['unmapped'])->toBe(1)
        ->and($report['safe_candidates'])->toBe(1)
        ->and($report['duplicate_canonical_target'])->toBe(0);
});

it('excludes every conflicting row targeting the same canonical booking', function () {
    $report = inspectAuditRows([auditRow(1, 301), auditRow(2, 301)]);

    expect($report['total'])->toBe(2)
        ->and($report['duplicate_canonical_target'])->toBe(2)
        ->and($report['safe_candidates'])->toBe(0);
});

it('blocks mismatched event and member relationships', function () {
    $report = inspectAuditRows([
        auditRow(1, 301, ['booking_event_id' => 99]),
        auditRow(2, 302, ['booking_member_id' => 99]),
    ]);

    expect($report['event_mismatch'])->toBe(1)
        ->and($report['member_mismatch'])->toBe(1)
        ->and($report['safe_candidates'])->toBe(0);
});

it('does not overwrite an attendance already linked to another booking', function () {
    $report = inspectAuditRows([auditRow(1, 301, ['assigned_booking_id' => 999])]);

    expect($report['assigned_booking_mismatch'])->toBe(1)
        ->and($report['safe_candidates'])->toBe(0)
        ->and($report['unmapped'])->toBe(0);
});

it('reports missing attendee, group and canonical booking independently', function () {
    $report = inspectAuditRows([
        auditRow(1, null, ['attendee_id' => null, 'group_id' => null]),
        auditRow(2, null, ['group_id' => null]),
        auditRow(3, null),
    ]);

    expect($report['missing_legacy_attendee'])->toBe(1)
        ->and($report['missing_booking_group'])->toBe(1)
        ->and($report['missing_canonical_booking'])->toBe(1)
        ->and($report['safe_candidates'])->toBe(0);
});

it('does not mistake a valid already-mapped attendance for a backfill candidate', function () {
    $report = inspectAuditRows([auditRow(1, 301, ['assigned_booking_id' => 301])]);

    expect($report['total'])->toBe(1)
        ->and($report['unmapped'])->toBe(0)
        ->and($report['safe_candidates'])->toBe(0)
        ->and($report['assigned_booking_mismatch'])->toBe(0);
});

it('rejects null member identities rather than treating null as integer zero', function () {
    $report = inspectAuditRows([
        auditRow(1, 301, ['attendee_member_id' => null, 'booking_member_id' => null]),
        auditRow(2, 302, ['attendee_member_id' => null]),
        auditRow(3, 303, ['booking_member_id' => null]),
    ]);

    expect($report['member_mismatch'])->toBe(3)
        ->and($report['safe_candidates'])->toBe(0);
});
