<?php

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingSideEffects;

/**
 * RED regression for Shared #25 / Tiandi #34.
 *
 * Current BookingEngine commits the booking before flush() invokes syncBooking().
 * A dispatch failure therefore throws after commit and cannot be retried from
 * durable intent. Do not weaken this test by merely catching the exception.
 */
it('keeps booking response successful and durable when post-commit sync dispatch fails', function (): void {
    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $effects = \Mockery::mock(BookingSideEffects::class);
    $effects->shouldReceive('syncBooking')
        ->once()
        ->with($member->id, $event->id)
        ->andThrow(new RuntimeException('synthetic queue dispatch failure'));
    app()->instance(BookingSideEffects::class, $effects);

    // This currently fails: the booking has committed but flush() propagates
    // the dispatch exception. The final solution must also persist retry intent.
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect($booking->exists)->toBeTrue();
    expect(EventBooking::query()->whereKey($booking->id)->where('status', EventBooking::STATUS_CONFIRMED)->exists())->toBeTrue();
});

/**
 * RED architecture guard: there must be an atomic durable side-effect intent
 * written before the outer booking transaction commits.
 *
 * This is intentionally an implementation-neutral test of a transaction
 * boundary, not a demand for a particular outbox table.
 */
it('does not depend on a post-transaction flush for durable booking effects', function (): void {
    $method = new \ReflectionMethod(BookingEngine::class, 'bookLegacy');
    $file = file($method->getFileName());
    $body = implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    // Source-level RED gate complements (does not replace) MySQL crash tests.
    expect($body)->not->toContain('$this->flush();');
});
