<?php

use App\Models\EventBooking;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingSideEffects;
use Illuminate\Support\Facades\DB;

/**
 * Behavioral regression: a connected member booking must persist its Google
 * handoff within the booking DB transaction, even if the old post-commit
 * adapter is unavailable. Do not assert a particular flush() source shape.
 */
it('persists booking and durable Google handoff without relying on post-commit adapter', function (): void {
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

    $effects = Mockery::mock(BookingSideEffects::class);
    $effects->shouldNotReceive('syncBooking');
    app()->instance(BookingSideEffects::class, $effects);

    $beforeJobs = DB::table('jobs')->count();
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect(EventBooking::query()->whereKey($booking->id)
        ->where('status', EventBooking::STATUS_CONFIRMED)->exists())->toBeTrue();
    expect(DB::table('jobs')->count())->toBeGreaterThan($beforeJobs);
});

/**
 * Behavioral rollback guard: no handoff job survives when a booking fails.
 * This complements the direct queue/mapping rollback test.
 */
it('does not leave a durable Google handoff after a rejected booking', function (): void {
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

    app(BookingEngine::class)->bookLegacy($event, $member);
    $beforeJobs = DB::table('jobs')->count();

    try {
        app(BookingEngine::class)->bookLegacy($event, $member);
        $this->fail('Duplicate booking unexpectedly accepted.');
    } catch (\App\Support\Api\ApiException $e) {
        expect($e->getMessage())->not->toBe('');
    }

    expect(DB::table('jobs')->count())->toBe($beforeJobs);
});
