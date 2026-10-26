<?php

use App\Jobs\DispatchBookingNotification;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;

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

it('sets bounded retry policy for booking notification handoff', function (): void {
    $job = new DispatchBookingNotification(123, 'Title', 'Body');

    expect($job->tries)->toBe(5);
    expect($job->backoff())->toBe([60, 300, 900, 3600]);
});
