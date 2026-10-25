<?php

use App\Models\AuditEvent;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Models\User;
use App\Services\Checkin\CheckinService;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

// Third review round: a check-in state change, the admin correction reason and the
// audit entry are one database transaction (M11-D12 / D24-A). Fault injection: the
// insert into one table is made to fail right before it reaches the database.

/** Makes every INSERT into $table fail like a database error until the returned switch is turned off. */
function failInsertsInto(string $table): Closure
{
    $active = true;
    DB::connection()->beforeExecuting(function (string $query) use ($table, &$active): void {
        if ($active && preg_match('/^\s*insert\s+into\s+[`"\[]?'.preg_quote($table, '/').'[`"\]]?\s/i', $query) === 1) {
            throw new QueryException(DB::getDefaultConnection(), $query, [], new RuntimeException("injected failure: insert into {$table}"));
        }
    });

    return function () use (&$active): void {
        $active = false;
    };
}

/** An occurrence whose check-in window is open, with one confirmed member attendee. */
function atomicOccurrence(): array
{
    $event = s9Event(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHours(2), 'checkin_enabled' => true, 'checkout_enabled' => true]);
    $member = User::factory()->create();
    groupBook($event, $member)->assertCreated();

    return [$event, $member, EventBookingAttendee::query()->where('calendar_event_id', $event->id)->sole()];
}

/** @return array<string, mixed> */
function checkinSnapshot(): array
{
    return [
        'attendances' => DB::table('event_attendances')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'corrections' => DB::table('event_attendance_corrections')->count(),
        'audits' => DB::table('audit_events')->where('event', 'like', 'checkin.%')->count(),
    ];
}

it('rolls back an admin correction when the reason or the audit entry cannot be written', function (string $failingTable) {
    [$event, , $attendee] = atomicOccurrence();
    $admin = makeAdmin();
    $service = app(CheckinService::class);

    // A first correction succeeds and becomes the state we must keep on failure.
    $service->adminSet($event, $attendee, EventAttendance::CHECKED_IN, $admin, '現場補登');
    $before = checkinSnapshot();
    expect($before['attendances'][0]['status'])->toBe('checked_in')
        ->and($before['corrections'])->toBe(1);

    $off = failInsertsInto($failingTable);
    expect(fn () => $service->adminSet($event, $attendee, EventAttendance::REVOKED, $admin, '誤登撤銷'))
        ->toThrow(QueryException::class, 'injected failure');
    $off();

    // Nothing of the failed correction is left: same status, no new reason, no new audit.
    expect(checkinSnapshot())->toBe($before)
        ->and(EventAttendance::query()->sole()->status)->toBe('checked_in');

    // A retry after the fault succeeds and writes all three parts together.
    $service->adminSet($event, $attendee, EventAttendance::REVOKED, $admin, '誤登撤銷');
    $after = checkinSnapshot();
    expect($after['attendances'][0]['status'])->toBe('revoked')
        ->and($after['corrections'])->toBe(2)
        ->and($after['audits'])->toBe($before['audits'] + 1);
})->with([
    'reason insert fails' => 'event_attendance_corrections',
    'audit insert fails' => 'audit_events',
]);

it('rolls back a first admin check-in completely when its audit entry fails', function () {
    [$event, , $attendee] = atomicOccurrence();
    $off = failInsertsInto('audit_events');

    expect(fn () => app(CheckinService::class)->adminSet($event, $attendee, EventAttendance::CHECKED_IN, makeAdmin(), null))
        ->toThrow(QueryException::class);
    $off();

    expect(DB::table('event_attendances')->count())->toBe(0)
        ->and(DB::table('event_attendance_corrections')->count())->toBe(0);
});

it('leaves no check-in or checkout without its audit entry', function () {
    [$event, , $attendee] = atomicOccurrence();
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('checkin_staff', 'web'));
    $service = app(CheckinService::class);

    // Check-in: the audit insert fails, so the attendance row is not kept either.
    $off = failInsertsInto('audit_events');
    expect(fn () => $service->staffCheckIn($event, $staff, null, $attendee->id))->toThrow(QueryException::class);
    $off();
    expect(DB::table('event_attendances')->count())->toBe(0);

    // A retry succeeds; a checkout whose audit fails keeps the checked-in state.
    $service->staffCheckIn($event, $staff, null, $attendee->id);
    $off = failInsertsInto('audit_events');
    expect(fn () => $service->checkOut($event, $attendee, $staff))->toThrow(QueryException::class);
    $off();
    expect(EventAttendance::query()->sole()->status)->toBe('checked_in')
        ->and(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(0);
});

it('keeps all check-in data when the purge evidence cannot be written', function () {
    [$event, , $attendee] = atomicOccurrence();
    app(CheckinService::class)->adminSet($event, $attendee, EventAttendance::CHECKED_IN, makeAdmin(), '補登');
    app(Settings::class)->put(['checkin_retention_days' => 30]);
    $this->travel(40)->days();
    $before = checkinSnapshot();

    $off = failInsertsInto('audit_events');
    expect(fn () => app(CheckinService::class)->purge())->toThrow(QueryException::class);
    $off();

    // No deletion without its evidence entry; existing audit rows untouched.
    expect(checkinSnapshot())->toBe($before)
        ->and(AuditEvent::query()->where('event', 'checkin.retention_purge')->count())->toBe(0);

    expect(app(CheckinService::class)->purge())->toMatchArray(['attendances_deleted' => 1, 'corrections_deleted' => 1]);
});

it('does not disguise an audit insert failure as an already checked-in scan when a revoked row exists', function () {
    [$event, , $attendee] = atomicOccurrence();
    $admin = makeAdmin();
    $service = app(CheckinService::class);
    $service->adminSet($event, $attendee, EventAttendance::REVOKED, $admin, '誤登撤銷');
    $before = checkinSnapshot();

    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('checkin_staff', 'web'));
    $off = failInsertsInto('audit_events');
    expect(fn () => $service->staffCheckIn($event, $staff, null, $attendee->id))
        ->toThrow(QueryException::class, 'injected failure');
    $off();

    expect(checkinSnapshot())->toBe($before)
        ->and(EventAttendance::query()->sole()->status)->toBe(EventAttendance::REVOKED);
});
