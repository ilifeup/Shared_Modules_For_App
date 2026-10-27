<?php

use App\Models\CalendarEvent;
use App\Models\Tag;
use App\Models\User;
use App\Services\BookingService;
use App\Support\S9DataGuard;

afterEach(fn () => putenv(S9DataGuard::CONFIRM_VARIABLE));

// Rollback safety (docs/s9_technical_decisions.md §6): data the migration can rebuild
// does not block; anything created while S9 was live does, until explicitly accepted.
it('allows a rollback when only rebuildable S9 data exists', function () {
    // A plain S6 event and booking: the migration's backfill rebuilds it after a re-run.
    $event = CalendarEvent::query()->create([
        'title' => 'S6 活動', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(),
        'status' => CalendarEvent::STATUS_PUBLISHED, 'audience' => CalendarEvent::AUDIENCE_MEMBERS,
        'booking_enabled' => true, 'booking_capacity' => 5,
    ]);
    app(BookingService::class)->book($event, User::factory()->create());

    expect(S9DataGuard::report())->toBe([]);
    S9DataGuard::assertRollbackAllowed();
});

it('refuses a rollback that would delete S9 data unless the loss is accepted', function () {
    Tag::query()->create(['name' => '共修']);
    groupBook(s9Event(), User::factory()->create())->assertCreated();

    $report = S9DataGuard::report();
    expect($report)->toMatchArray([
        'tags' => 1,
        'event_categories' => 1,
        'event_booking_groups (S9 bookings)' => 1,
        'event_booking_attendees (S9 bookings)' => 1,
    ]);
    expect(fn () => S9DataGuard::assertRollbackAllowed())->toThrow(RuntimeException::class, 'S9 rollback refused');

    // The migration's down() uses the same guard and stops before dropping anything.
    $migration = require database_path('migrations/2026_11_01_000100_s9_modules.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'S9_ROLLBACK_ACCEPT_DATA_LOSS=yes');
    expect(Tag::query()->count())->toBe(1);

    putenv(S9DataGuard::CONFIRM_VARIABLE.'=yes');
    S9DataGuard::assertRollbackAllowed();
});

it('reports what a rollback would delete without changing anything', function () {
    Tag::query()->create(['name' => '報表']);

    $this->artisan('s9:data-report')->expectsOutputToContain('would permanently delete')->assertSuccessful();
    expect(Tag::query()->count())->toBe(1);
});
