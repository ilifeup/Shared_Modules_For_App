<?php

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CheckinLocation;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Models\User;
use App\Services\Checkin\CheckinService;
use App\Services\Checkin\CheckinTokens;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Spatie\Permission\Models\Role;

/** An occurrence open for check-in now, with the member's confirmed group (self + one guest name). */
function checkinEvent(array $attributes = []): array
{
    $event = s9Event(array_merge([
        'starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHours(2),
        'checkin_enabled' => true, 'checkout_enabled' => true, 'booking_capacity' => 5,
    ], $attributes));
    $member = User::factory()->create();
    groupBook($event, $member, ['家人'])->assertCreated();
    $attendees = EventBookingAttendee::query()->where('calendar_event_id', $event->id)->orderBy('id')->get();

    return [$event, $member, $attendees];
}

function staffMember(): User
{
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('checkin_staff', 'web'));

    return $staff;
}

// G10 — 60-second rotation with a 10-second grace for the previous window, server time.
it('accepts the current event token and the previous one only within 10 seconds', function () {
    $tokens = app(CheckinTokens::class);
    $start = 1_800_000_000 - (1_800_000_000 % 60); // start of a window

    $token = $tokens->event(7, $start + 30);
    expect($tokens->verify($token, 'e', $start + 59))->toBe(7)
        ->and($tokens->verify($token, 'e', $start + 60 + 9))->toBe(7)
        ->and($tokens->verify($token, 'e', $start + 60 + 10))->toBeNull()
        ->and($tokens->verify($token, 'a', $start + 30))->toBeNull()
        ->and($tokens->verify(str_replace('.7.', '.8.', $token), 'e', $start + 30))->toBeNull()
        ->and($tokens->expiresIn($start + 45))->toBe(15);
});

// G02 / G03 / G07 / G14 — member scans the event QR; each attendee separately; duplicates say "already".
it('checks attendees in one by one with the event QR and never twice', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id], 'event_token' => $token])
        ->assertOk()->assertJsonPath('data.0.result', 'checked_in');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id, $attendees[1]->id], 'event_token' => $token])
        ->assertOk()->assertJsonPath('data.0.result', 'already_checked_in')->assertJsonPath('data.1.result', 'checked_in');

    expect(EventAttendance::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(2);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin")
        ->assertJsonPath('data.attendees.0.checkin_status', 'checked_in')->assertJsonPath('data.settings.open_now', true);

    // Another member cannot check in these attendees; an expired token is refused.
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[1]->id], 'event_token' => $token])->assertNotFound();
    $old = app(CheckinTokens::class)->event($event->id, now()->getTimestamp() - 120);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[1]->id], 'event_token' => $old])
        ->assertStatus(422)->assertJsonPath('error.code', 'QR_EXPIRED');
});

// G06 / D30 — time window per occurrence; waitlisted cannot check in.
it('refuses check-in outside the window and for waitlisted attendees', function () {
    [$event, $member, $attendees] = checkinEvent(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6)]);
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id], 'event_token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'CHECKIN_CLOSED');

    $full = s9Event(['booking_capacity' => 1, 'waitlist_enabled' => true, 'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHour(), 'checkin_enabled' => true]);
    groupBook($full, User::factory()->create());
    $waiting = User::factory()->create();
    groupBook($full, $waiting)->assertJsonPath('data.status', 'waitlisted');
    $attendee = EventBookingAttendee::query()->where('member_user_id', $waiting->id)->sole();
    $this->actingAs($waiting, 'sanctum')->postJson("/api/v1/events/{$full->id}/checkin", ['attendee_ids' => [$attendee->id], 'event_token' => app(CheckinTokens::class)->event($full->id)])
        ->assertStatus(409)->assertJsonPath('error.code', 'WAITLISTED');
});

// G08 / G09 — Location QR for many occurrences, GPS from the event or the App default.
it('uses a fixed Location QR with optional GPS and the event setting first', function () {
    [$event, $member, $attendees] = checkinEvent(['checkin_gps_required' => true, 'checkin_gps_radius_m' => 100]);
    $location = CheckinLocation::query()->create(['name' => '大殿', 'latitude' => 25.0330, 'longitude' => 121.5654]);
    $location->events()->attach($event->id);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/checkin/locations/{$location->token}")
        ->assertOk()->assertJsonPath('data.events.0.id', (string) $event->id)->assertJsonPath('data.events.0.settings.gps_required', true);

    $post = fn (array $gps) => $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id], 'location_token' => $location->token] + $gps);
    $post([])->assertStatus(422)->assertJsonPath('error.code', 'GPS_REQUIRED');
    $post(['lat' => 25.0430, 'lng' => 121.5654, 'accuracy' => 20])->assertStatus(422)->assertJsonPath('error.code', 'GPS_OUT_OF_RANGE');
    $post(['lat' => 25.0335, 'lng' => 121.5654, 'accuracy' => 15])->assertOk()->assertJsonPath('data.0.result', 'checked_in');

    // Not linked to this location: refused.
    [$other, $otherMember, $otherAttendees] = checkinEvent();
    $this->actingAs($otherMember, 'sanctum')->postJson("/api/v1/events/{$other->id}/checkin", ['attendee_ids' => [$otherAttendees[0]->id], 'location_token' => $location->token])
        ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_EVENT_MISMATCH');

    // App default applies when the event does not decide (D26).
    $other->update(['checkin_gps_required' => null]);
    $location->events()->attach($other->id);
    app(Settings::class)->put(['checkin_gps_required' => false]);
    $this->actingAs($otherMember, 'sanctum')->postJson("/api/v1/events/{$other->id}/checkin", ['attendee_ids' => [$otherAttendees[0]->id], 'location_token' => $location->token])->assertOk();
});

// G11 / G16 / D29 — staff scan the attendee pass; only staff see the list.
it('lets authorised staff scan attendee passes and read the attendance list', function () {
    [$event, $member, $attendees] = checkinEvent();
    $pass = $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin/pass")->assertOk()->json('data.passes.1.token');

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", ['attendee_token' => $pass])->assertStatus(403);
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/attendance")->assertStatus(403);
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin/qr")->assertStatus(403);

    $staff = staffMember();
    $this->actingAs($staff, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin/qr")->assertOk()->assertJsonPath('data.window_seconds', 60)->assertJsonPath('data.grace_seconds', 10);
    $this->actingAs($staff, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", ['attendee_token' => $pass])->assertOk()->assertJsonPath('data.result', 'checked_in');

    $list = $this->actingAs($staff, 'sanctum')->getJson("/api/v1/events/{$event->id}/attendance")->assertOk();
    expect($list->json('data.summary'))->toMatchArray(['booked' => 2, 'checked_in' => 1, 'absent' => 1, 'checked_out' => 0])
        ->and($list->getContent())->not->toContain($member->email);
});

// G12 / D27 — checkout via the same QR entry; no self re-check-in afterwards.
it('checks out with the same QR and does not let members check in again', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $body = ['attendee_ids' => [$attendees[0]->id], 'event_token' => $token];

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $body)->assertStatus(409)->assertJsonPath('error.code', 'NOT_CHECKED_IN');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", $body)->assertOk();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $body)->assertOk()->assertJsonPath('data.0.result', 'checked_out');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", $body)->assertOk()->assertJsonPath('data.0.result', 'already_checked_out');

    $event->update(['checkout_enabled' => false]);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", ['attendee_ids' => [$attendees[1]->id], 'event_token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'CHECKOUT_DISABLED');
});

// G13 / G14 — admin late check-in needs a reason; corrections are audited with before/after.
it('requires a reason for admin late check-in and corrections', function () {
    [$event, , $attendees] = checkinEvent();
    $event->update(['starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)]);
    $admin = makeAdmin();
    $service = app(CheckinService::class);

    expect(fn () => $service->adminSet($event, $attendees[0], EventAttendance::CHECKED_IN, $admin, null))->toThrow(ApiException::class);
    $service->adminSet($event, $attendees[0], EventAttendance::CHECKED_IN, $admin, '現場核對後補登');
    $service->adminSet($event, $attendees[0], EventAttendance::REVOKED, $admin, '誤登');

    $audits = AuditEvent::query()->where('event', 'like', 'checkin.admin_%')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->details['late'])->toBeTrue()
        ->and($audits[1]->details['before'])->toBe(['status' => 'checked_in'])
        ->and($audits[1]->details['after'])->toBe(['status' => 'revoked'])
        ->and($audits[1]->details['reason'])->toBe('誤登');

    // Members cannot do this; staff check-in also respects the window.
    $staff = staffMember();
    expect(fn () => $service->staffCheckIn($event, $staff, null, $attendees[1]->id))->toThrow(ApiException::class);
});

// G17 / D28 — retention: delete check-in records, keep de-identified audit only.
it('purges old check-ins and de-identifies their audit entries', function () {
    [$event, $member, $attendees] = checkinEvent();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id], 'event_token' => app(CheckinTokens::class)->event($event->id)])->assertOk();
    app(Settings::class)->put(['checkin_retention_days' => 30]);

    $this->travel(40)->days();
    $result = app(CheckinService::class)->purge();

    $audit = AuditEvent::query()->where('event', 'checkin.checked_in')->sole();
    expect($result['attendances_deleted'])->toBe(1)
        ->and(EventAttendance::query()->count())->toBe(0)
        ->and($audit->details)->not->toHaveKey('attendee_id')
        ->and($audit->details['deidentified'])->toBeTrue()
        ->and($audit->subject_id)->toBeNull();
});

// G05 — every occurrence of a series has its own attendance.
it('keeps attendance per occurrence', function () {
    [$event, $member, $attendees] = checkinEvent();
    $second = s9Event(['starts_at' => now()->addMinutes(20), 'ends_at' => now()->addHours(3), 'checkin_enabled' => true]);
    groupBook($second, $member)->assertCreated();
    $secondAttendee = EventBookingAttendee::query()->where('calendar_event_id', $second->id)->sole();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_ids' => [$attendees[0]->id], 'event_token' => app(CheckinTokens::class)->event($event->id)])->assertOk();
    // A token of one occurrence does not work for another.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$second->id}/checkin", ['attendee_ids' => [$secondAttendee->id], 'event_token' => app(CheckinTokens::class)->event($event->id)])
        ->assertStatus(422);

    expect(EventAttendance::query()->where('calendar_event_id', $second->id)->count())->toBe(0)
        ->and(CalendarEvent::query()->count())->toBe(2);
});
