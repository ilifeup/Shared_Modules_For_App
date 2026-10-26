<?php

use App\Jobs\DispatchBookingGoogleSync;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Calendar\GoogleCalendarSync;
use Illuminate\Support\Facades\DB;

it('dispatches existing Google sync job and mapping when durable handoff is processed', function (): void {
    config()->set('queue.default', 'database');

    $event = s9Event();
    $member = User::factory()->create();
    GoogleCalendarConnection::query()->create([
        'user_id' => $member->id,
        'status' => GoogleCalendarConnection::ACTIVE,
        'google_calendar_id' => 'synthetic-calendar',
    ]);

    $before = DB::table('jobs')->count();
    (new DispatchBookingGoogleSync($member->id, $event->id))->handle(app(GoogleCalendarSync::class));

    expect(DB::table('jobs')->count())->toBe($before + 1);
    expect(GoogleCalendarEventMapping::query()
        ->where('source_type', GoogleCalendarEventMapping::SOURCE_BOOKING)
        ->where('source_id', $event->id)
        ->exists())->toBeTrue();
});

it('does not enqueue Google sync from durable handoff when connection is absent', function (): void {
    config()->set('queue.default', 'database');

    $event = s9Event();
    $member = User::factory()->create();
    $before = DB::table('jobs')->count();

    (new DispatchBookingGoogleSync($member->id, $event->id))->handle(app(GoogleCalendarSync::class));

    expect(DB::table('jobs')->count())->toBe($before);
    expect(GoogleCalendarEventMapping::query()->count())->toBe(0);
});

it('enqueues durable handoff in the booking transaction for a connected member', function (): void {
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

    $before = DB::table('jobs')->count();
    $booking = app(BookingEngine::class)->bookLegacy($event, $member);

    expect($booking->exists)->toBeTrue();
    expect(DB::table('jobs')->count())->toBeGreaterThan($before);
});
