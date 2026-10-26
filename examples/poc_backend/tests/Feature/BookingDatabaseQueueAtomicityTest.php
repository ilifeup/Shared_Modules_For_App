<?php

use App\Jobs\SyncGoogleCalendarItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Database queue feasibility gate for Shared #25.
 * Uses real database queue insertion; do not fake the queue or use sync driver.
 * RefreshDatabase wraps tests in a transaction, so these assertions establish
 * savepoint-level atomicity, NOT independent-process crash recovery.
 */
it('writes database queue jobs on the same database connection inside the booking transaction', function (): void {
    expect(DB::getDefaultConnection())->toBe('mysql');
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
