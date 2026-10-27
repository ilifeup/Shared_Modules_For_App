<?php

use App\Jobs\DispatchBookingGoogleSync;
use App\Models\EventBooking;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;

/**
 * Booking must persist a handoff before invoking any external Google sync.
 * Worker failures are retried by the queued handoff, not during booking.
 */
it('persists a retryable Google sync handoff before executing the real adapter', function (): void {
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

    $beforeJobs = DB::table('jobs')->count();

    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect(EventBooking::query()->whereKey($booking->id)->where('status', EventBooking::STATUS_CONFIRMED)->exists())->toBeTrue();

    // The booking request does not execute GoogleCalendarSync inline.
    expect(DB::table('jobs')->count())->toBeGreaterThan($beforeJobs);
});

/**
 * Positive control: with an active connection and no injected failure, the
 * current real Google adapter should enqueue a database job.
 * Distinguishes a lost-intent regression from an invalid Google fixture.
 */
it('queues Google sync for a connected member when the real adapter succeeds', function (): void {
    config()->set('queue.default', 'database');

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
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect($booking->exists)->toBeTrue();
    expect(DB::table('jobs')->count())->toBeGreaterThan($beforeJobs);
    // Booking writes a durable handoff, not a Google mapping. The mapping is
    // created only when the worker executes the handoff after commit.
    expect(GoogleCalendarEventMapping::query()
        ->where('source_type', GoogleCalendarEventMapping::SOURCE_BOOKING)
        ->where('source_id', $event->id)
        ->exists())->toBeFalse();

    (new DispatchBookingGoogleSync($member->id, $event->id))->handle(app(GoogleCalendarSync::class));

    expect(GoogleCalendarEventMapping::query()
        ->where('source_type', GoogleCalendarEventMapping::SOURCE_BOOKING)
        ->where('source_id', $event->id)
        ->exists())->toBeTrue();
});

/**
 * Negative control: an unconnected member must not create Google mapping or
 * Google sync job merely because a booking was made.
 */
it('does not queue Google sync for a member without a Google connection', function (): void {
    config()->set('queue.default', 'database');

    $event = s9Event();
    $member = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $beforeJobs = DB::table('jobs')->count();
    $beforeMappings = GoogleCalendarEventMapping::query()->count();

    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect($booking->exists)->toBeTrue();
    expect(DB::table('jobs')->count())->toBe($beforeJobs);
    expect(GoogleCalendarEventMapping::query()->count())->toBe($beforeMappings);
});
