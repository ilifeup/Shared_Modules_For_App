<?php

use App\Jobs\DispatchBookingNotification;
use App\Models\EventBookingGroup;
use App\Services\Booking\BookingEngine;
use App\Models\User;
use App\Services\MemberNotifier;

it('does nothing if notification booking group no longer exists', function (): void {
    $notifier = Mockery::mock(MemberNotifier::class);
    $notifier->shouldNotReceive('send');

    (new DispatchBookingNotification(999999999, 'Title', 'Body'))->handle($notifier);
});

it('propagates member notifier failures so the queue can retry', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);
    $group = EventBookingGroup::query()
        ->where('legacy_booking_id', $booking->id)->firstOrFail();

    $notifier = Mockery::mock(MemberNotifier::class);
    $notifier->shouldReceive('send')->once()
        ->andThrow(new RuntimeException('synthetic notification outage'));

    expect(fn () => (new DispatchBookingNotification($group->id, 'Title', 'Body'))->handle($notifier))
        ->toThrow(RuntimeException::class, 'synthetic notification outage');
});

it('sets bounded retry policy for booking notification handoff', function (): void {
    $job = new DispatchBookingNotification(123, 'Title', 'Body');

    expect($job->tries)->toBe(5);
    expect($job->backoff())->toBe([60, 300, 900, 3600]);
});
