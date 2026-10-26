<?php

use App\Models\EventBooking;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;

/**
 * Real BookingSideEffects path: GoogleCalendarSync::queueBooking() throws,
 * BookingSideEffects catches/logs, and the booking still commits.
 *
 * RED expectation: the booking transaction must leave a durable retry intent.
 * This intentionally does NOT mock BookingSideEffects itself.
 */
it('persists a retryable Google sync intent when the real side-effect adapter swallows dispatch failure', function (): void {
    config()->set('queue.default', 'database');

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    // A Google sync job is only expected for a connected member. Without this
    // fixture, an empty jobs table is legitimate and the RED test is misleading.
    GoogleCalendarConnection::query()->create([
        'user_id' => $member->id,
        'status' => GoogleCalendarConnection::ACTIVE,
        'google_calendar_id' => 'synthetic-calendar',
    ]);

    $google = Mockery::mock(GoogleCalendarSync::class);
    $google->shouldReceive('queueBooking')
        ->once()
        ->with($member->id, $event->id)
        ->andThrow(new RuntimeException('synthetic Google queue failure'));
    app()->instance(GoogleCalendarSync::class, $google);

    $beforeJobs = DB::table('jobs')->count();

    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect(EventBooking::query()->whereKey($booking->id)->where('status', EventBooking::STATUS_CONFIRMED)->exists())->toBeTrue();

    // Current code loses the pending sync intent after the exception is logged.
    // A fix can use a transactional database job or minimal durable outbox;
    // replace this jobs-specific assertion if an outbox is selected.
    expect(DB::table('jobs')->count())->toBeGreaterThan($beforeJobs);
});
