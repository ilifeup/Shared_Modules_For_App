<?php

use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function calendarEvent(array $attributes = []): CalendarEvent
{
    return CalendarEvent::query()->create(array_merge([
        'title' => '活動',
        'starts_at' => '2026-11-10 02:00:00',
        'ends_at' => '2026-11-10 04:00:00',
        'status' => CalendarEvent::STATUS_PUBLISHED,
        'audience' => CalendarEvent::AUDIENCE_MEMBERS,
    ], $attributes));
}

function eventsBetween(string $from, string $to, ?User $member = null)
{
    $query = '?from='.urlencode($from).'&to='.urlencode($to);

    return $member === null
        ? test()->getJson('/api/v1/public/events'.$query)
        : test()->actingAs($member, 'sanctum')->getJson('/api/v1/events'.$query);
}

it('returns published and cancelled events in the range, never drafts', function () {
    $published = calendarEvent(['title' => '已發布']);
    $cancelled = calendarEvent(['title' => '已取消', 'starts_at' => '2026-11-11 02:00:00', 'ends_at' => '2026-11-11 03:00:00']);
    $cancelled->cancel('颱風');
    $draft = calendarEvent(['title' => '草稿', 'status' => CalendarEvent::STATUS_DRAFT]);
    $member = User::factory()->create();

    eventsBetween('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z', $member)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $published->id)
        ->assertJsonPath('data.0.starts_at', '2026-11-10T02:00:00Z')
        ->assertJsonPath('data.1.status', 'cancelled')
        ->assertJsonPath('data.1.cancel_reason', '颱風')
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('meta.truncated', false);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$draft->id}")->assertNotFound();
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$cancelled->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.title', '已取消');

    // Cancelling keeps the row and its id.
    expect(CalendarEvent::query()->find($cancelled->id)?->cancelled_at)->not->toBeNull();
});

it('exposes configured waitlist limit in the event API', function () {
    $event = calendarEvent(['waitlist_capacity' => 3, 'waitlist_enabled' => true]);
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.waitlist_capacity', 3)
        ->assertJsonPath('data.waitlist_enabled', true);
});

it('keeps member events away from the public and from unverified members', function () {
    calendarEvent(['title' => '會員活動']);
    $public = calendarEvent(['title' => '公開活動', 'audience' => CalendarEvent::AUDIENCE_PUBLIC]);
    $membersOnly = CalendarEvent::query()->where('title', '會員活動')->firstOrFail();

    eventsBetween('2026-11-01', '2026-12-01')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $public->id);
    $this->getJson("/api/v1/public/events/{$membersOnly->id}")->assertNotFound();
    $this->getJson('/api/v1/events?from=2026-11-01&to=2026-12-01')->assertUnauthorized();

    $unverified = User::factory()->unverified()->create();
    eventsBetween('2026-11-01', '2026-12-01', $unverified)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');

    eventsBetween('2026-11-01', '2026-12-01', User::factory()->create())->assertJsonCount(2, 'data');
});

it('selects by overlap across month and year boundaries', function () {
    $newYear = calendarEvent(['title' => '跨年', 'starts_at' => '2026-12-31 14:00:00', 'ends_at' => '2027-01-01 02:00:00']);
    $january = calendarEvent(['title' => '一月', 'starts_at' => '2027-01-15 01:00:00', 'ends_at' => '2027-01-15 02:00:00']);
    $member = User::factory()->create();

    // December (UTC) contains the new-year event; January (UTC) contains both.
    eventsBetween('2026-12-01', '2027-01-01', $member)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $newYear->id);
    eventsBetween('2027-01-01', '2027-02-01', $member)->assertJsonCount(2, 'data');

    // An event ending exactly at `from` or starting exactly at `to` is outside the range.
    eventsBetween('2027-01-01T02:00:00Z', '2027-01-15T01:00:00Z', $member)->assertJsonCount(0, 'data');
    expect($january->id)->toBeInt();
});

it('applies the client time zone offset of the requested range', function () {
    // 2026-11-30 23:30 in Taipei (UTC+8) is 15:30 UTC on the 30th.
    $lateNight = calendarEvent(['starts_at' => '2026-11-30 15:30:00', 'ends_at' => '2026-11-30 16:30:00']);
    $member = User::factory()->create();

    // Taipei "1 December" = 2026-11-30T16:00Z .. 2026-12-01T16:00Z: the event (ends 16:30Z) overlaps it.
    eventsBetween('2026-12-01T00:00:00+08:00', '2026-12-02T00:00:00+08:00', $member)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.from', '2026-11-30T16:00:00Z');

    // Taipei "30 November" also contains it.
    eventsBetween('2026-11-30T00:00:00+08:00', '2026-12-01T00:00:00+08:00', $member)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $lateNight->id);
});

it('validates the range', function () {
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/events')->assertStatus(422);
    eventsBetween('2026-11-02', '2026-11-01', $member)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['to']]]);
    eventsBetween('2026-11-01', '2027-02-01', $member)->assertStatus(422);
    eventsBetween('yesterday', '2026-11-01', $member)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['from']]]);
});

it('limits the number of events returned per query', function () {
    $rows = [];
    foreach (range(1, CalendarEvent::MAX_RESULTS + 5) as $i) {
        $rows[] = ['title' => "E{$i}", 'starts_at' => Carbon::parse('2026-11-01 00:00:00')->addMinutes($i), 'ends_at' => Carbon::parse('2026-11-01 00:00:00')->addMinutes($i + 30), 'status' => 'published', 'audience' => 'members', 'created_at' => now(), 'updated_at' => now()];
    }
    CalendarEvent::query()->insert($rows);

    eventsBetween('2026-11-01', '2026-11-02', User::factory()->create())
        ->assertJsonCount(CalendarEvent::MAX_RESULTS, 'data')
        ->assertJsonPath('meta.truncated', true);
});

it('lets an admin create, publish and cancel events in Filament without deleting them', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['app.display_timezone' => 'Asia/Taipei']);
    $this->actingAs(makeAdmin());

    Livewire::test(CreateCalendarEvent::class)
        ->fillForm([
            'title' => '新春團拜',
            'location' => '大殿',
            'audience' => CalendarEvent::AUDIENCE_MEMBERS,
            'status' => CalendarEvent::STATUS_DRAFT,
            'starts_at' => '2027-02-06 10:00',
            'ends_at' => '2027-02-06 12:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = CalendarEvent::query()->where('title', '新春團拜')->firstOrFail();
    // Entered in Taipei time, stored in UTC.
    expect($event->starts_at->toIso8601ZuluString())->toBe('2027-02-06T02:00:00Z');

    Livewire::test(ListCalendarEvents::class)
        ->searchTable('新春')
        ->assertCanSeeTableRecords([$event])
        ->callTableAction('publish', $event);
    expect($event->refresh()->status)->toBe(CalendarEvent::STATUS_PUBLISHED);

    Livewire::test(ListCalendarEvents::class)
        ->assertTableActionHidden('delete', $event)
        ->callTableAction('cancelEvent', $event, data: ['cancel_reason' => '場地維修']);

    expect($event->refresh()->status)->toBe(CalendarEvent::STATUS_CANCELLED)
        ->and($event->cancel_reason)->toBe('場地維修')
        ->and(AuditEvent::query()->whereIn('event', ['event.published', 'event.cancelled'])->count())->toBe(2);
});

it('shows the calendar admin only with events.manage', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('events.manage');
    $this->actingAs($admin->refresh());

    $this->get('/admin/calendar-events')->assertForbidden();
});

it('rejects invalid intervals on direct model create and update but accepts overnight events', function () {
    foreach (['2026-11-10 02:00:00', '2026-11-10 01:00:00'] as $invalidEnd) {
        try {
            calendarEvent(['ends_at' => $invalidEnd]);
            test()->fail('Invalid event interval was saved');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('ends_at');
        }
    }

    $event = calendarEvent([
        'starts_at' => '2026-11-10 23:00:00',
        'ends_at' => '2026-11-11 01:00:00',
    ]);
    expect($event->exists)->toBeTrue();

    foreach (['2026-11-10 23:00:00', '2026-11-10 22:00:00'] as $invalidEnd) {
        try {
            $event->update(['ends_at' => $invalidEnd]);
            test()->fail('Invalid event update was saved');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('ends_at');
        }
        $event->refresh();
        expect($event->ends_at->toDateTimeString())->toBe('2026-11-11 01:00:00');
    }
});
