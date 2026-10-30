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
