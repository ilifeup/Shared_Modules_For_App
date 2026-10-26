<?php

use App\Jobs\SyncGoogleCalendarItem;
use App\Models\EventBooking;
use App\Services\Booking\BookingEngine;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\User;
use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;

/**
 * Database queue feasibility gate for Shared #25.
 * Uses real database queue insertion; do not fake the queue or use sync driver.
 * RefreshDatabase wraps tests in a transaction, so these assertions establish
 * savepoint-level atomicity, NOT independent-process crash recovery.
 */
it('writes database queue jobs on the same database connection inside the booking transaction', function (): void {
    expect(DB::getDefaultConnection())->toBe('mysql');
    config()->set('queue.default', 'database');
    expect(config('queue.default'))->toBe('database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    $queueConnection = config('queue.connections.database.connection') ?: config('database.default');
    expect($queueConnection)->toBe(DB::getDefaultConnection());

    $before = DB::table('jobs')->count();

    DB::transaction(function () use ($before): void {
        SyncGoogleCalendarItem::dispatch(1, 'booking', 100001);

        expect(DB::table('jobs')->count())->toBe($before + 1);
    });

    expect(DB::table('jobs')->count())->toBe($before + 1);
});

it('rolls back a queued Google sync job with its enclosing database transaction', function (): void {
    expect(DB::getDefaultConnection())->toBe('mysql');
    config()->set('queue.default', 'database');
    expect(config('queue.default'))->toBe('database');
    expect(config('queue.connections.database.after_commit'))->toBeFalse();

    $before = DB::table('jobs')->count();

    try {
        DB::transaction(function (): void {
            SyncGoogleCalendarItem::dispatch(1, 'booking', 100002);

            expect(DB::table('jobs')->count())->toBeGreaterThan(0);
            throw new RuntimeException('synthetic transaction rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('synthetic transaction rollback');
    }

    expect(DB::table('jobs')->count())->toBe($before);
});

/**
 * A durable booking-side sync intent must not survive a failed booking write.
 * This tests the actual mapping + job path inside an enclosing transaction.
 */
it('rolls back both Google mapping and database job if enclosing booking transaction fails', function (): void {
    config()->set('queue.default', 'database');

    $member = User::factory()->create();
    $connection = GoogleCalendarConnection::query()->create([
        'user_id' => $member->id,
        'status' => GoogleCalendarConnection::ACTIVE,
        'google_calendar_id' => 'synthetic-calendar',
    ]);
    $event = s9Event();
    $beforeJobs = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($member, $event): void {
            app(GoogleCalendarSync::class)->queueBooking($member->id, $event->id);

            expect(DB::table('jobs')->count())->toBeGreaterThan(0);
            throw new RuntimeException('synthetic booking rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('synthetic booking rollback');
    }

    expect(DB::table('jobs')->count())->toBe($beforeJobs);
    expect(GoogleCalendarEventMapping::query()
        ->where('google_calendar_connection_id', $connection->id)
        ->where('source_type', GoogleCalendarEventMapping::SOURCE_BOOKING)
        ->where('source_id', $event->id)
        ->exists())->toBeFalse();
});

/**
 * Exercise the actual BookingEngine write, rather than just dispatching a job
 * directly. A forced outer transaction rollback must remove both the booking
 * and the durable handoff. RefreshDatabase still limits this to MySQL
 * transaction/savepoint evidence, not a separate-process crash simulation.
 */
it('rolls back BookingEngine booking and durable handoff together', function (): void {
    expect(DB::getDefaultConnection())->toBe('mysql');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', null);

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    GoogleCalendarConnection::query()->create([
        'user_id' => $member->id,
        'status' => GoogleCalendarConnection::ACTIVE,
        'google_calendar_id' => 'synthetic-calendar',
    ]);

    $beforeJobs = DB::table('jobs')->count();
    $beforeBookings = EventBooking::query()->count();

    try {
        DB::transaction(function () use ($event, $member, $beforeJobs): void {
            $booking = app(BookingEngine::class)->bookLegacy($event, $member);
            expect($booking->exists)->toBeTrue();
            expect(DB::table('jobs')->count())->toBeGreaterThan($beforeJobs);
            throw new RuntimeException('synthetic outer booking rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('synthetic outer booking rollback');
    }

    expect(DB::table('jobs')->count())->toBe($beforeJobs);
    expect(EventBooking::query()->count())->toBe($beforeBookings);
});
