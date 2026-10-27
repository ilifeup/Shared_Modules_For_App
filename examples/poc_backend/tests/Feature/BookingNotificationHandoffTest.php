<?php

use App\Jobs\DispatchBookingNotification;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('logs a missing booking group without creating a notification', function (): void {
    Log::spy();
    $before = DB::table('notifications')->count();
    $job = new DispatchBookingNotification(999999999, 'Title', 'Body');

    $job->handle(app(MemberNotifier::class));

    expect(DB::table('notifications')->count())->toBe($before);
    Log::shouldHaveReceived('warning')->once()->with(
        'booking notification skipped: group missing',
        ['group_id' => 999999999, 'delivery_id' => $job->deliveryId],
    );
});

it('propagates real notifier validation failures without swallowing them', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);
    $group = EventBookingGroup::query()
        ->where('legacy_booking_id', $booking->id)->firstOrFail();

    // MemberNotifier is final; use the real service and its validation error
    // to prove the handoff does not swallow worker exceptions.
    $notifier = app(MemberNotifier::class);

    expect(fn () => (new DispatchBookingNotification($group->id, '', 'Body'))->handle($notifier))
        ->toThrow(ApiException::class);
});

it('delivers a real member booking notification using the existing notifier', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);
    $group = EventBookingGroup::query()->where('legacy_booking_id', $booking->id)->firstOrFail();

    (new DispatchBookingNotification($group->id, 'Booking update', 'Your booking was updated'))
        ->handle(app(MemberNotifier::class));

    expect($member->fresh()->notifications()->where('data->title', 'Booking update')->count())->toBe(1);
});

it('does not duplicate member notifications when the same worker payload is replayed', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);
    $group = EventBookingGroup::query()->where('legacy_booking_id', $booking->id)->firstOrFail();

    $job = new DispatchBookingNotification($group->id, 'Member replay', 'Repeated worker delivery');
    $job->handle(app(MemberNotifier::class));
    $job->handle(app(MemberNotifier::class));

    expect($member->fresh()->notifications()->where('data->title', 'Member replay')->count())->toBe(1);
});

it('delivers to the current booker when booking ownership changes before processing', function (): void {
    $event = s9Event();
    $original = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $replacement = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $original);
    $group = EventBookingGroup::query()->where('legacy_booking_id', $booking->id)->firstOrFail();
    $job = new DispatchBookingNotification($group->id, 'Ownership update', 'Changed booking owner');

    $group->update(['booker_user_id' => $replacement->id]);
    $job->handle(app(MemberNotifier::class));

    expect($original->fresh()->notifications()->where('data->title', 'Ownership update')->count())->toBe(0);
    expect($replacement->fresh()->notifications()->where('data->title', 'Ownership update')->count())->toBe(1);
});

it('delivers separate notification jobs even when their text is identical', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);
    $group = EventBookingGroup::query()->where('legacy_booking_id', $booking->id)->firstOrFail();

    $first = new DispatchBookingNotification($group->id, 'Same title', 'Same body');
    $second = new DispatchBookingNotification($group->id, 'Same title', 'Same body');

    expect($first->deliveryId)->not->toBe($second->deliveryId);

    $first->handle(app(MemberNotifier::class));
    $second->handle(app(MemberNotifier::class));
    $first->handle(app(MemberNotifier::class));

    expect($member->fresh()->notifications()->where('data->title', 'Same title')->count())->toBe(2);
});

it('sets bounded retry policy for booking notification handoff', function (): void {
    $job = new DispatchBookingNotification(123, 'Title', 'Body');

    expect($job->tries)->toBe(5);
    expect($job->backoff())->toBe([60, 300, 900, 3600]);
});

it('persists an actual booking change notification job before the worker runs', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', null);

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    app(BookingEngine::class)->bookLegacy($event, $member);
    $before = DB::table('jobs')->count();

    expect(app(BookingEngine::class)->notifyChange($event, 'Event update', 'Schedule changed'))->toBe(1);

    expect(DB::table('jobs')->count())->toBeGreaterThan($before);
    expect($member->fresh()->notifications()->count())->toBe(0);
});

it('processes a real queued booking notification with the database worker', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', null);

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    app(BookingEngine::class)->bookLegacy($event, $member);

    expect(app(BookingEngine::class)->notifyChange($event, 'Worker update', 'Processed by queue worker'))->toBe(1);
    expect($member->fresh()->notifications()->where('data->title', 'Worker update')->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBeGreaterThan(0);

    $exitCode = Artisan::call('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--tries' => 1,
        '--sleep' => 0,
    ]);

    expect($exitCode)->toBe(0);
    expect($member->fresh()->notifications()->where('data->title', 'Worker update')->count())->toBe(1);
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
});

it('rolls back an actual booking change notification with its transaction', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', null);

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    app(BookingEngine::class)->bookLegacy($event, $member);
    $before = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($event, $before): void {
            expect(app(BookingEngine::class)->notifyChange($event, 'Event update', 'Schedule changed'))->toBe(1);
            expect(DB::table('jobs')->count())->toBeGreaterThan($before);
            throw new RuntimeException('rollback real booking change notification');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('rollback real booking change notification');
    }

    expect(DB::table('jobs')->count())->toBe($before);
    expect($member->fresh()->notifications()->count())->toBe(0);
});

it('rolls back a queued notification handoff with its enclosing transaction', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', null);

    $before = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($before): void {
            DispatchBookingNotification::dispatch(123, 'Title', 'Body')->onConnection('database');

            expect(DB::table('jobs')->count())->toBe($before + 1);
            throw new RuntimeException('synthetic notification handoff rollback');
        });
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('synthetic notification handoff rollback');
    }

    expect(DB::table('jobs')->count())->toBe($before);
});
