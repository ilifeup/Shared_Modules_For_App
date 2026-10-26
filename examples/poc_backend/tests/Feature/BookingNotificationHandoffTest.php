<?php

use App\Jobs\DispatchBookingNotification;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;
use Illuminate\Support\Facades\DB;

it('does nothing if notification booking group no longer exists', function (): void {
    $notifier = app(MemberNotifier::class);

    (new DispatchBookingNotification(999999999, 'Title', 'Body'))->handle($notifier);
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

it('delivers a real guest booking notification to the guest group', function (): void {
    $event = s9Event();
    $group = EventBookingGroup::query()->create([
        'calendar_event_id' => $event->id,
        'is_guest' => true,
        'contact_name' => 'Guest',
        'contact_email' => 'guest@example.test',
        'status' => EventBookingGroup::STATUS_CONFIRMED,
        'seat_count' => 1,
        'booked_at' => now(),
    ]);

    (new DispatchBookingNotification($group->id, 'Guest update', 'Guest booking was updated'))
        ->handle(app(MemberNotifier::class));

    expect($group->fresh()->notifications()->where('data->title', 'Guest update')->count())->toBe(1);
});

it('currently creates duplicate notifications when the same worker payload is replayed', function (): void {
    $event = s9Event();
    $group = EventBookingGroup::query()->create([
        'calendar_event_id' => $event->id,
        'is_guest' => true,
        'contact_name' => 'Replay Guest',
        'contact_email' => 'replay@example.test',
        'status' => EventBookingGroup::STATUS_CONFIRMED,
        'seat_count' => 1,
        'booked_at' => now(),
    ]);

    $job = new DispatchBookingNotification($group->id, 'Replay update', 'Replay notification');
    $job->handle(app(MemberNotifier::class));
    $job->handle(app(MemberNotifier::class));

    // Characterization of the CURRENT BUG, not a desired acceptance criterion.
    // Change expected count to 1 when stable delivery-id deduplication is implemented.
    expect($group->fresh()->notifications()->where('data->title', 'Replay update')->count())->toBe(2);
});

it('currently creates duplicate member notifications when the same worker payload is replayed', function (): void {
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

    // KNOWN BUG characterization, not acceptance: expected 1 after deduplication.
    expect($member->fresh()->notifications()->where('data->title', 'Member replay')->count())->toBe(2);
});

it('sets bounded retry policy for booking notification handoff', function (): void {
    $job = new DispatchBookingNotification(123, 'Title', 'Body');

    expect($job->tries)->toBe(5);
    expect($job->backoff())->toBe([60, 300, 900, 3600]);
});

/**
 * Existing notification handoff is NOT transactionally wired into BookingEngine
 * yet. This tests the component's queue persistence and rollback contract only.
 */
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
