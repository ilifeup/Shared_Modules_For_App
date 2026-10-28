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
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** An occurrence open for check-in now, with one confirmed registered-member booking. */
function checkinEvent(array $attributes = []): array
{
    $event = s9Event(array_merge([
        'starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHours(2),
        'checkin_enabled' => true, 'checkout_enabled' => true, 'booking_capacity' => 5,
    ], $attributes));
    $member = User::factory()->create();
    groupBook($event, $member)->assertCreated();
    // Two independent verified-member bookings, never a multi-attendee booking.
    $secondMember = User::factory()->create();
    groupBook($event, $secondMember)->assertCreated();
    $attendees = EventBookingAttendee::query()->where('calendar_event_id', $event->id)->orderBy('id')->get();

    return [$event, $member, $attendees];
}

function staffMember(): User
{
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('checkin_staff', 'web'));

    return $staff;
}

it('accepts scalar attendee_id for member check-in and rejects another member', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id, 'event_token' => $token,
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_in');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[1]->id, 'event_token' => $token,
    ])->assertNotFound();
});

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

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => $token])
        ->assertOk()->assertJsonPath('data.0.result', 'checked_in');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => $token])
        ->assertOk()->assertJsonPath('data.0.result', 'already_checked_in');

    expect(EventAttendance::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(1);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin")
        ->assertJsonPath('data.attendees.0.checkin_status', 'checked_in')->assertJsonPath('data.settings.open_now', true);

    // Another member cannot check in these attendees; an expired token is refused.
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => $token])->assertNotFound();
    $old = app(CheckinTokens::class)->event($event->id, now()->getTimestamp() - 120);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => $old])
        ->assertStatus(422)->assertJsonPath('error.code', 'QR_EXPIRED');
});

// G06 / D30 — time window per occurrence; waitlisted cannot check in.
it('refuses check-in outside the window and for waitlisted attendees', function () {
    [$event, $member, $attendees] = checkinEvent(['starts_at' => now()->addHours(5), 'ends_at' => now()->addHours(6)]);
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => $token])
        ->assertStatus(409)->assertJsonPath('error.code', 'CHECKIN_CLOSED');

    $full = s9Event(['booking_capacity' => 1, 'waitlist_enabled' => true, 'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHour(), 'checkin_enabled' => true]);
    groupBook($full, User::factory()->create());
    $waiting = User::factory()->create();
    groupBook($full, $waiting)->assertJsonPath('data.status', 'waitlisted');
    $attendee = EventBookingAttendee::query()->where('member_user_id', $waiting->id)->sole();
    $this->actingAs($waiting, 'sanctum')->postJson("/api/v1/events/{$full->id}/checkin", ['attendee_id' => $attendee->id, 'event_token' => app(CheckinTokens::class)->event($full->id)])
        ->assertStatus(409)->assertJsonPath('error.code', 'WAITLISTED');
});

// G08 / G09 — Location QR for many occurrences, GPS from the event or the App default.
it('uses a fixed Location QR with optional GPS and the event setting first', function () {
    [$event, $member, $attendees] = checkinEvent(['checkin_gps_required' => true, 'checkin_gps_radius_m' => 100]);
    $location = CheckinLocation::query()->create(['name' => '大殿', 'latitude' => 25.0330, 'longitude' => 121.5654]);
    $location->events()->attach($event->id);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/checkin/locations/{$location->token}")
        ->assertOk()->assertJsonPath('data.events.0.id', (string) $event->id)->assertJsonPath('data.events.0.settings.gps_required', true);

    $post = fn (array $gps) => $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'location_token' => $location->token] + $gps);
    $post([])->assertStatus(422)->assertJsonPath('error.code', 'GPS_REQUIRED');
    $post(['lat' => 25.0430, 'lng' => 121.5654, 'accuracy' => 20])->assertStatus(422)->assertJsonPath('error.code', 'GPS_OUT_OF_RANGE');
    $post(['lat' => 25.0335, 'lng' => 121.5654, 'accuracy' => 15])->assertOk()->assertJsonPath('data.0.result', 'checked_in');

    // Not linked to this location: refused.
    [$other, $otherMember, $otherAttendees] = checkinEvent();
    $this->actingAs($otherMember, 'sanctum')->postJson("/api/v1/events/{$other->id}/checkin", ['attendee_id' => $otherAttendees[0]->id, 'location_token' => $location->token])
        ->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_EVENT_MISMATCH');

    // App default applies when the event does not decide (D26).
    $other->update(['checkin_gps_required' => null]);
    $location->events()->attach($other->id);
    app(Settings::class)->put(['checkin_gps_required' => false]);
    $this->actingAs($otherMember, 'sanctum')->postJson("/api/v1/events/{$other->id}/checkin", ['attendee_id' => $otherAttendees[0]->id, 'location_token' => $location->token])->assertOk();
});

// M11 default: no GPS unless the event explicitly enables it or inherits an enabled App setting.
it('defaults location QR check-in to no GPS and lets event settings override the App', function () {
    app(Settings::class)->put(['checkin_gps_required' => false]);
    [$defaultEvent, $defaultMember, $defaultAttendees] = checkinEvent(['checkin_gps_required' => null]);
    $defaultLocation = CheckinLocation::query()->create([
        'name' => '預設免定位', 'latitude' => 25.0330, 'longitude' => 121.5654,
    ]);
    $defaultLocation->events()->attach($defaultEvent->id);

    $this->actingAs($defaultMember, 'sanctum')->getJson("/api/v1/checkin/locations/{$defaultLocation->token}")
        ->assertOk()->assertJsonPath('data.events.0.settings.gps_required', false);
    $this->actingAs($defaultMember, 'sanctum')->postJson("/api/v1/events/{$defaultEvent->id}/checkin", [
        'attendee_id' => $defaultAttendees[0]->id, 'location_token' => $defaultLocation->token,
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_in');

    app(Settings::class)->put(['checkin_gps_required' => true]);
    [$overrideEvent, $overrideMember, $overrideAttendees] = checkinEvent(['checkin_gps_required' => false]);
    $overrideLocation = CheckinLocation::query()->create([
        'name' => '活動關閉定位', 'latitude' => 25.0330, 'longitude' => 121.5654,
    ]);
    $overrideLocation->events()->attach($overrideEvent->id);

    $this->actingAs($overrideMember, 'sanctum')->getJson("/api/v1/checkin/locations/{$overrideLocation->token}")
        ->assertOk()->assertJsonPath('data.events.0.settings.gps_required', false);
    $this->actingAs($overrideMember, 'sanctum')->postJson("/api/v1/events/{$overrideEvent->id}/checkin", [
        'attendee_id' => $overrideAttendees[0]->id, 'location_token' => $overrideLocation->token,
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_in');
});

// M11 #38 decision B: location QR checkout does not require GPS, even if check-in does.
it('checks out by a linked location QR without GPS while keeping event binding and check-in GPS rules', function () {
    [$event, $member, $attendees] = checkinEvent(['checkin_gps_required' => true, 'checkin_gps_radius_m' => 100]);
    $location = CheckinLocation::query()->create(['name' => '簽退地點', 'latitude' => 25.0330, 'longitude' => 121.5654]);
    $location->events()->attach($event->id);
    $locationBody = ['attendee_id' => $attendees[0]->id, 'location_token' => $location->token];

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", $locationBody)
        ->assertStatus(422)->assertJsonPath('error.code', 'GPS_REQUIRED');

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $locationBody)
        ->assertStatus(409)->assertJsonPath('error.code', 'NOT_CHECKED_IN');

    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id, 'event_token' => $token,
    ])->assertOk();

    $unlinked = CheckinLocation::query()->create(['name' => '不屬於此活動', 'latitude' => 25.0330, 'longitude' => 121.5654]);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_id' => $attendees[0]->id, 'location_token' => $unlinked->token,
    ])->assertStatus(422)->assertJsonPath('error.code', 'LOCATION_EVENT_MISMATCH');

    // GPS fields are ignored on checkout, including stale or malformed client values.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $locationBody + [
        'lat' => 'invalid', 'lng' => 'invalid', 'accuracy' => 'invalid',
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_out');

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $locationBody)
        ->assertOk()->assertJsonPath('data.0.result', 'already_checked_out');

    expect(EventAttendance::query()->where('event_booking_attendee_id', $attendees[0]->id)->firstOrFail()->status)
        ->toBe(EventAttendance::CHECKED_OUT);
});

// G11 / G16 / D29 — staff scan the attendee pass; only staff see the list.
it('lets authorised staff scan attendee passes and read the attendance list', function () {
    [$event, $member, $attendees] = checkinEvent();
    $pass = $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/checkin/pass")->assertOk()->json('data.passes.0.token');

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
    $body = ['attendee_id' => $attendees[0]->id, 'event_token' => $token];

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $body)->assertStatus(409)->assertJsonPath('error.code', 'NOT_CHECKED_IN');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", $body)->assertOk();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", $body)->assertOk()->assertJsonPath('data.0.result', 'checked_out');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", $body)->assertOk()->assertJsonPath('data.0.result', 'already_checked_out');

    $event->update(['checkout_enabled' => false]);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", ['attendee_id' => $attendees[0]->id, 'event_token' => $token])
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
        ->and($audits[1]->details)->not->toHaveKeys(['reason', 'attendee_id'])
        ->and($audits[1]->details['reason_recorded'])->toBeTrue()
        ->and($audits[1]->actor_id)->toBe($admin->id);

    // The reasons are kept with the attendance (personal check-in data, purged at retention).
    $corrections = DB::table('event_attendance_corrections')->orderBy('id')->get();
    expect($corrections->pluck('reason')->all())->toBe(['現場核對後補登', '誤登'])
        ->and($corrections[1]->from_status)->toBe('checked_in')
        ->and((int) $audits[1]->details['correction_id'])->toBe((int) $corrections[1]->id);

    // Members cannot do this; staff check-in also respects the window.
    $staff = staffMember();
    expect(fn () => $service->staffCheckIn($event, $staff, null, $attendees[1]->id))->toThrow(ApiException::class);
});

// G17 / D24 / D28 — retention deletes check-in personal data; the audit log stays
// append-only (never rewritten) because check-in audits never hold attendee data.
it('purges old check-ins without rewriting the append-only audit log', function () {
    [$event, $member, $attendees] = checkinEvent();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => app(CheckinTokens::class)->event($event->id)])->assertOk();
    $staff = staffMember();
    $this->actingAs($staff, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", ['attendee_id' => $attendees[1]->id])->assertOk();
    $event->update(['starts_at' => now()->subHours(5), 'ends_at' => now()->subHours(4)]);
    app(CheckinService::class)->adminSet($event, $attendees[1], EventAttendance::REVOKED, makeAdmin(), '王小明誤刷');
    app(Settings::class)->put(['checkin_retention_days' => 30]);

    $audits = AuditEvent::query()->where('event', 'like', 'checkin.%')->orderBy('id')->get();
    $before = $audits->map(fn (AuditEvent $a) => $a->getAttributes())->all();
    // Written de-identified: no attendee id or reason; a member checking in is not named.
    expect($audits)->toHaveCount(3)
        ->and($audits->every(fn (AuditEvent $a) => ! array_key_exists('attendee_id', (array) $a->details) && ! array_key_exists('reason', (array) $a->details)))->toBeTrue()
        ->and($audits[0]->actor_id)->toBeNull()->and($audits[0]->details['actor_role'])->toBe('member')
        ->and($audits[1]->actor_id)->toBe($staff->id)->and($audits[1]->details['actor_role'])->toBe('staff')
        ->and(json_encode($before, JSON_UNESCAPED_UNICODE))->not->toContain('王小明');

    // Not yet expired: nothing is deleted.
    expect(app(CheckinService::class)->purge()['attendances_deleted'])->toBe(0);

    $this->travel(40)->days();
    $result = app(CheckinService::class)->purge();

    expect($result)->toMatchArray(['attendances_deleted' => 2, 'corrections_deleted' => 1, 'retention_days' => 30])
        ->and(EventAttendance::query()->count())->toBe(0)
        ->and(DB::table('event_attendance_corrections')->count())->toBe(0)
        // Existing audit rows are byte-for-byte unchanged ...
        ->and(AuditEvent::query()->whereIn('id', $audits->pluck('id'))->orderBy('id')->get()->map(fn (AuditEvent $a) => $a->getAttributes())->all())->toBe($before)
        // ... and the run itself is recorded as evidence.
        ->and(AuditEvent::query()->where('event', 'checkin.retention_purge')->latest('id')->firstOrFail()->details)->toMatchArray(['attendances_deleted' => 2, 'corrections_deleted' => 1]);

    // The model still refuses any change to audit rows.
    expect(fn () => $audits[0]->forceFill(['details' => []])->save())->toThrow(LogicException::class);
});

// G05 — every occurrence of a series has its own attendance.
it('keeps attendance per occurrence', function () {
    [$event, $member, $attendees] = checkinEvent();
    $second = s9Event(['starts_at' => now()->addMinutes(20), 'ends_at' => now()->addHours(3), 'checkin_enabled' => true]);
    groupBook($second, $member)->assertCreated();
    $secondAttendee = EventBookingAttendee::query()->where('calendar_event_id', $second->id)->sole();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => app(CheckinTokens::class)->event($event->id)])->assertOk();
    // A token of one occurrence does not work for another.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$second->id}/checkin", ['attendee_id' => $secondAttendee->id, 'event_token' => app(CheckinTokens::class)->event($event->id)])
        ->assertStatus(422);

    expect(EventAttendance::query()->where('calendar_event_id', $second->id)->count())->toBe(0)
        ->and(CalendarEvent::query()->count())->toBe(2);
});

it('rejects another members real attendee ID in check-in and checkout batches', function () {
    [$event, $member, $attendees] = checkinEvent();
    $other = User::factory()->create();
    groupBook($event, $other, [])->assertCreated();
    $foreign = EventBookingAttendee::query()->where('calendar_event_id', $event->id)
        ->where('member_user_id', $other->id)->firstOrFail();
    $token = app(CheckinTokens::class)->event($event->id);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_ids' => [$attendees[0]->id, $foreign->id],
        'event_token' => $token,
    ])->assertStatus(422);
    expect(EventAttendance::query()->where('calendar_event_id', $event->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(0);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id,
        'event_token' => $token,
    ])->assertOk();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_ids' => [$attendees[0]->id, $foreign->id],
        'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('event_booking_attendee_id', $attendees[0]->id)->firstOrFail()->status)
        ->toBe(EventAttendance::CHECKED_IN);
    expect(EventAttendance::query()->where('event_booking_attendee_id', $foreign->id)->count())->toBe(0);
    expect(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(0);
});

it('rejects a mixed-ownership check-in batch before any attendee is checked in', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_ids' => [$attendees[0]->id, 999999999],
        'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('calendar_event_id', $event->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(0);
});

it('rejects a mixed-ownership checkout batch before changing attendance', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id,
        'event_token' => $token,
    ])->assertOk();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_ids' => [$attendees[0]->id, 999999999],
        'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('event_booking_attendee_id', $attendees[0]->id)->first()->status)
        ->toBe(EventAttendance::CHECKED_IN);
    expect(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(0);
});

// #36: checkout must not partially commit when a later owned attendee is not checked in.
// One-member-per-booking: the API must reject a cross-member batch before writing.
it('rejects cross-member checkout atomically without audit writes', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id, 'event_token' => $token,
    ])->assertOk();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_ids' => [$attendees[0]->id, $attendees[1]->id], 'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('event_booking_attendee_id', $attendees[0]->id)->firstOrFail()->status)
        ->toBe(EventAttendance::CHECKED_IN)
        ->and(EventAttendance::query()->where('event_booking_attendee_id', $attendees[1]->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(0);
});

it('rejects cross-member check-in before processing a waitlisted attendee', function () {
    [$event, $member, $attendees] = checkinEvent();
    $attendees[1]->forceFill(['status' => EventBookingAttendee::STATUS_WAITLISTED])->save();
    $token = app(CheckinTokens::class)->event($event->id);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_ids' => [$attendees[0]->id, $attendees[1]->id], 'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('calendar_event_id', $event->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(0);
});

it('keeps individual member check-in and checkout retries idempotent', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $attendeeId = $attendees[0]->id;

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendeeId, 'event_token' => $token,
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_in');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendeeId, 'event_token' => $token,
    ])->assertOk()->assertJsonPath('data.0.result', 'already_checked_in');

    expect(EventAttendance::query()->where('calendar_event_id', $event->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(1);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_id' => $attendeeId, 'event_token' => $token,
    ])->assertOk()->assertJsonPath('data.0.result', 'checked_out');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_id' => $attendeeId, 'event_token' => $token,
    ])->assertOk()->assertJsonPath('data.0.result', 'already_checked_out');

    expect(EventAttendance::query()->where('status', EventAttendance::CHECKED_OUT)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(1);
});

it('rejects cross-member check-in before audit insertion, leaving no partial writes', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_ids' => [$attendees[0]->id, $attendees[1]->id], 'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('calendar_event_id', $event->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_in')->count())->toBe(0);
});

it('rejects cross-member checkout before audit insertion, leaving both records unchanged', function () {
    [$event, $member, $attendees] = checkinEvent();
    $token = app(CheckinTokens::class)->event($event->id);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", [
        'attendee_id' => $attendees[0]->id, 'event_token' => $token,
    ])->assertOk();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkout", [
        'attendee_ids' => [$attendees[0]->id, $attendees[1]->id], 'event_token' => $token,
    ])->assertStatus(422);

    expect(EventAttendance::query()->where('event_booking_attendee_id', $attendees[0]->id)->firstOrFail()->status)
        ->toBe(EventAttendance::CHECKED_IN)
        ->and(EventAttendance::query()->where('event_booking_attendee_id', $attendees[1]->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('event', 'checkin.checked_out')->count())->toBe(0);
});
