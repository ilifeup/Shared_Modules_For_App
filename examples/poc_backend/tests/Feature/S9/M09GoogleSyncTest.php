<?php

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\MembershipLevel;
use App\Models\PrivateCalendarEvent;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Calendar\GoogleCalendarSync;
use App\Services\MembershipService;
use App\Support\Settings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Status the fake Calendar event endpoint answers (changeable inside one test). */
function googleEventStatus(?int $set = null): int
{
    static $status = 200;
    if ($set !== null) {
        $status = $set;
    }

    return $status;
}

// MOCK Google APIs (Http::fake). No real OAuth or Calendar API is called here.
function fakeGoogle(int $eventStatus = 200): void
{
    googleEventStatus($eventStatus);
    config([
        'google_calendar.client_id' => 'client-id',
        'google_calendar.client_secret' => 'client-secret',
        'google_calendar.redirect_uri' => 'https://app.example/oauth/google/callback',
    ]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-secret', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/v3/calendars' => Http::response(['id' => 'app-cal@group.calendar.google.com']),
        'www.googleapis.com/calendar/v3/calendars/*/events/*' => fn () => Http::response([], googleEventStatus()),
        'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response([], 200),
    ]);
}

function connectGoogle(User $member): GoogleCalendarConnection
{
    $url = test()->actingAs($member, 'sanctum')->postJson('/api/v1/me/google-calendar/connect')->assertOk()->json('data.authorization_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    test()->get('/oauth/google/callback?state='.$query['state'].'&code=auth-code')->assertOk()->assertSee('已連結 Google 日曆');

    return GoogleCalendarConnection::query()->where('user_id', $member->id)->firstOrFail();
}

/** @return list<HttpRequest> */
function googleEventCalls(string $method): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => $r->method() === $method && str_contains($r->url(), '/events'))->values()->all();
}

it('reports GOOGLE_NOT_CONFIGURED when the App has no Google credentials (BLOCKED in real use)', function () {
    $member = User::factory()->create();
    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/google-calendar')->assertJsonPath('data.configured', false)->assertJsonPath('data.connected', false);
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/google-calendar/connect')->assertStatus(503)->assertJsonPath('error.code', 'GOOGLE_NOT_CONFIGURED');
});

// G09 / G10 — one Google account, least-privilege scope, App calendar, tokens encrypted.
it('connects with a one-time state, creates the App calendar and keeps tokens encrypted', function () {
    fakeGoogle();
    $member = User::factory()->create();

    $url = $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/google-calendar/connect')->json('data.authorization_url');
    expect($url)->toContain('calendar.app.created')->toContain('access_type=offline');

    $connection = connectGoogle($member);
    expect($connection->google_calendar_id)->toBe('app-cal@group.calendar.google.com')
        ->and($connection->refresh_token)->toBe('rt-secret')
        ->and(DB::table('google_calendar_connections')->value('refresh_token'))->not->toContain('rt-secret');

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/google-calendar')
        ->assertJsonPath('data.connected', true)->assertJsonMissingPath('data.refresh_token')->assertDontSee('rt-secret');

    // A state works once; a replayed state is rejected.
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $this->get('/oauth/google/callback?state='.$query['state'].'&code=x')->assertSee('已連結 Google 日曆');
    $this->get('/oauth/google/callback?state='.$query['state'].'&code=x')->assertSee('連結失敗');
});

// G11 / G12 / G15 — my bookings sync one-way, one event per member and occurrence; cancel removes it.
it('syncs a booking once per member and occurrence and removes it after cancelling', function () {
    fakeGoogle();
    $member = User::factory()->create();
    $connection = connectGoogle($member);
    $event = s9Event(['location' => '道場', 'floor' => '2F']);

    groupBook($event, $member, ['家人一', '家人二'])->assertCreated();
    $put = googleEventCalls('PUT');
    $eventId = GoogleCalendarSync::googleEventId($connection, 'booking', $event->id);
    expect($put)->toHaveCount(1)
        ->and($put[0]->url())->toEndWith('/events/'.$eventId)
        ->and($put[0]['location'])->toBe('道場 2F')
        ->and(GoogleCalendarEventMapping::query()->count())->toBe(1);

    // Retrying the same item updates the same Google event (deterministic id), never a new one.
    app(GoogleCalendarSync::class)->sync($connection, 'booking', $event->id);
    expect(collect(googleEventCalls('PUT'))->map->url()->unique()->all())->toHaveCount(1);

    $group = EventBookingGroup::query()->sole();
    app(BookingEngine::class)->cancelGroup($group, $member);
    expect(googleEventCalls('DELETE'))->toHaveCount(1)
        ->and(GoogleCalendarEventMapping::query()->sole()->status)->toBe('removed');
});

// G14 — disconnect keeps Google events and stops further sync.
it('keeps existing Google events and stops syncing after disconnecting', function () {
    fakeGoogle();
    $member = User::factory()->create();
    connectGoogle($member);
    $event = s9Event();
    groupBook($event, $member)->assertCreated();

    $this->actingAs($member, 'sanctum')->deleteJson('/api/v1/me/google-calendar')->assertOk();
    $connection = GoogleCalendarConnection::query()->sole();
    expect($connection->status)->toBe('disconnected')->and($connection->refresh_token)->toBeNull();

    app(BookingEngine::class)->cancelGroup(EventBookingGroup::query()->sole(), $member);
    expect(googleEventCalls('DELETE'))->toHaveCount(0);
});

// G13 / G16 — VIP private entries are private in Google; after VIP ends nothing more syncs.
it('syncs VIP private entries as private and stops when VIP ends', function () {
    fakeGoogle();
    app(Settings::class)->put(['calendar_vip_level_codes' => 'vip']);
    $member = User::factory()->create();
    $assignment = app(MembershipService::class)->assign($member, MembershipLevel::query()->where('code', 'vip')->firstOrFail(), now()->subDay(), null, null, makeAdmin());
    connectGoogle($member);

    $start = now()->addDays(3);
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/private-events', [
        'title' => '私人', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(),
        'recurrence' => 'weekly', 'recurrence_until' => $start->copy()->addWeeks(2)->toDateString(),
    ])->assertCreated();

    $put = googleEventCalls('PUT');
    expect($put)->toHaveCount(1)
        ->and($put[0]['visibility'])->toBe('private')
        ->and($put[0]['recurrence'][0])->toStartWith('RRULE:FREQ=WEEKLY;UNTIL=');

    $assignment->forceFill(['ends_at' => now()->subMinute()])->save();
    $entry = PrivateCalendarEvent::query()->sole();
    app(GoogleCalendarSync::class)->queuePrivate($entry);
    expect(googleEventCalls('PUT'))->toHaveCount(1)->and(googleEventCalls('DELETE'))->toHaveCount(0);
});

// L03 A — limited retries end in "failed", visible to admins, retry possible; booking unaffected.
it('marks failed items for an admin retry without touching the booking', function () {
    fakeGoogle(500);
    $member = User::factory()->create();
    connectGoogle($member);
    $event = s9Event();

    groupBook($event, $member)->assertCreated()->assertJsonPath('data.status', 'confirmed');
    $mapping = GoogleCalendarEventMapping::query()->sole();
    expect($mapping->status)->toBe('failed')
        ->and($mapping->last_error)->toContain('HTTP 500')
        ->and(EventBookingGroup::query()->sole()->status)->toBe('confirmed');

    googleEventStatus(200);
    app(GoogleCalendarSync::class)->retry($mapping, makeAdmin());
    expect($mapping->refresh()->status)->toBe('synced');
});

// Review fix: an event may exist in Google although the last call failed, so a later
// cancellation still deletes it (404 = already gone is fine).
it('deletes the Google event after a cancellation even when the last sync had failed', function () {
    fakeGoogle(500);
    $member = User::factory()->create();
    connectGoogle($member);
    groupBook(s9Event(), $member)->assertCreated();
    expect(GoogleCalendarEventMapping::query()->sole()->status)->toBe('failed');

    googleEventStatus(200);
    app(BookingEngine::class)->cancelGroup(EventBookingGroup::query()->sole(), $member);
    expect(googleEventCalls('DELETE'))->toHaveCount(1)
        ->and(GoogleCalendarEventMapping::query()->sole()->status)->toBe('removed');
});

// Guest booking and claim were removed: a registered member's booking syncs directly.
it('syncs a registered member booking to Google without guest claim', function () {
    fakeGoogle();
    $member = User::factory()->create(['email' => 'member-sync@example.com']);
    connectGoogle($member);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC]);

    groupBook($event, $member)->assertCreated();
    expect(googleEventCalls('PUT'))->toHaveCount(1);

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/v1/me/guest-bookings/1/claim')
        ->assertNotFound();
});

// M09-D24: the first sync after connecting covers current and future bookings only.
it('syncs only current and future bookings when a member connects', function () {
    fakeGoogle();
    $member = User::factory()->create();
    $past = s9Event(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
    $future = s9Event();
    groupBook($past, $member)->assertCreated();
    groupBook($future, $member)->assertCreated();
    $past->update(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour()]);

    $connection = connectGoogle($member);
    $put = collect(googleEventCalls('PUT'))->map->url();
    expect($put)->toHaveCount(1)
        ->and($put->first())->toEndWith('/events/'.GoogleCalendarSync::googleEventId($connection, 'booking', $future->id));
});

// M09-D12: a changed occurrence updates the member's Google event (same event id).
it('updates the Google event when the occurrence changes', function () {
    fakeGoogle();
    $member = User::factory()->create();
    connectGoogle($member);
    $event = s9Event(['location' => '舊地點']);
    groupBook($event, $member)->assertCreated();

    $event->update(['location' => '新地點']);
    app(BookingEngine::class)->notifyChange($event->refresh(), '活動異動', '地點改為新地點');

    $put = googleEventCalls('PUT');
    expect($put)->toHaveCount(2)
        ->and($put[0]->url())->toBe($put[1]->url())
        ->and($put[1]['location'])->toStartWith('新地點');
});
