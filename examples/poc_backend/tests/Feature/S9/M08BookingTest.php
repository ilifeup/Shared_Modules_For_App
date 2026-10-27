<?php

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\EventWaitlistEntry;
use App\Models\MemberVerification;
use App\Models\User;
use App\Models\VerificationType;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\LegacyBookingBackfill;
use App\Services\BookingService;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// G01 / G04 — one seat ledger for the S6 and S9 entry points (S9-L06 A).
it('counts S6 single bookings and S9 group attendees in one ledger', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $legacy = User::factory()->create();
    $booker = User::factory()->create();

    $this->actingAs($legacy, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")
        ->assertCreated()->assertJsonPath('data.booked_count', 1)->assertJsonPath('data.remaining', 2);

    groupBook($event, $booker, ['同行者'])->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.seat_count', 2)
        ->assertJsonCount(2, 'data.attendees');

    expect(seats($event))->toBe(3);
    $this->actingAs($legacy, 'sanctum')->getJson("/api/v1/events/{$event->id}/booking")
        ->assertJsonPath('data.booked_count', 3)->assertJsonPath('data.remaining', 0);

    // The S6 endpoint keeps its semantics: full = 409 BOOKING_FULL (no waitlist there).
    $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")
        ->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_FULL');
    expect(seats($event))->toBe(3);
});

it('prevents the same member from holding two places through old and new entry points', function () {
    $event = s9Event();
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")->assertCreated();
    groupBook($event, $member)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_BOOKED');

    $other = User::factory()->create();
    groupBook($event, $other)->assertCreated();
    $this->actingAs($other, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_BOOKED');
    $this->actingAs($other, 'sanctum')->getJson("/api/v1/events/{$event->id}/booking")->assertJsonPath('data.unavailable_reason', 'already_booked');

    expect(EventBookingAttendee::query()->where('member_user_id', $member->id)->where('active', 1)->count())->toBe(1)
        ->and(seats($event))->toBe(2);
});

// G07 / G08 — strict FIFO, whole groups, the head group blocks smaller later ones.
it('waitlists whole groups and never lets a later group jump the head group', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $a = User::factory()->create();
    groupBook($event, $a, ['A2'])->assertJsonPath('data.status', 'confirmed'); // 2 seats, 1 left

    $b = User::factory()->create();
    $c = User::factory()->create();
    groupBook($event, $b, ['B2', 'B3'])->assertCreated()->assertJsonPath('data.status', 'waitlisted')->assertJsonPath('data.waitlist_position', 1);
    // Only 1 seat is free but the head group (3) is waiting: C (1) must not jump the queue.
    groupBook($event, $c)->assertCreated()->assertJsonPath('data.status', 'waitlisted')->assertJsonPath('data.waitlist_position', 2);
    expect(seats($event))->toBe(2);

    // A cancels: 3 free → B (3) is promoted as a whole; C stays waiting (no seat left).
    $groupA = EventBookingGroup::query()->where('booker_user_id', $a->id)->firstOrFail();
    $this->actingAs($a, 'sanctum')->postJson("/api/v1/me/booking-groups/{$groupA->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $groupB = EventBookingGroup::query()->where('booker_user_id', $b->id)->firstOrFail();
    $groupC = EventBookingGroup::query()->where('booker_user_id', $c->id)->firstOrFail();
    expect($groupB->status)->toBe('confirmed')
        ->and($groupB->attendees()->where('status', 'confirmed')->count())->toBe(3)
        ->and($groupC->status)->toBe('waitlisted')
        ->and(seats($event))->toBe(3)
        ->and(AuditEvent::query()->where('event', 'booking.promoted')->count())->toBe(1);

    // D13: the promoted member gets an in-app notification.
    expect($b->notifications()->count())->toBe(1)
        ->and($b->notifications()->first()->data['title'])->toContain('候補成功');
});

it('lets members withdraw from the waitlist at any time and promotes after S6 cancels too', function () {
    $event = s9Event(['booking_capacity' => 1]);
    $legacy = User::factory()->create();
    $this->actingAs($legacy, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")->assertCreated();

    $waiting = User::factory()->create();
    $second = User::factory()->create();
    groupBook($event, $waiting)->assertJsonPath('data.status', 'waitlisted');
    groupBook($event, $second)->assertJsonPath('data.status', 'waitlisted');

    $first = EventBookingGroup::query()->where('booker_user_id', $waiting->id)->firstOrFail();
    $this->actingAs($waiting, 'sanctum')->postJson("/api/v1/me/booking-groups/{$first->id}/cancel")->assertOk();

    // S6 cancel releases the seat; the next waiting group (second) is promoted at once.
    $booking = EventBooking::query()->where('user_id', $legacy->id)->firstOrFail();
    $this->actingAs($legacy, 'sanctum')->postJson("/api/v1/me/bookings/{$booking->id}/cancel")->assertOk();

    expect(EventBookingGroup::query()->where('booker_user_id', $second->id)->value('status'))->toBe('confirmed')
        ->and(seats($event))->toBe(1);
});

// G08 — permanent failure skips with a reason; nothing else does.
it('skips a waiting group whose member account was disabled and promotes the next one', function () {
    $event = s9Event(['booking_capacity' => 1]);
    $holder = User::factory()->create();
    groupBook($event, $holder)->assertJsonPath('data.status', 'confirmed');
    $gone = User::factory()->create();
    $next = User::factory()->create();
    groupBook($event, $gone)->assertJsonPath('data.status', 'waitlisted');
    groupBook($event, $next)->assertJsonPath('data.status', 'waitlisted');
    $gone->forceFill(['is_active' => false])->save();

    $group = EventBookingGroup::query()->where('booker_user_id', $holder->id)->firstOrFail();
    $this->actingAs($holder, 'sanctum')->postJson("/api/v1/me/booking-groups/{$group->id}/cancel")->assertOk();

    expect(EventWaitlistEntry::query()->whereHas('group', fn ($q) => $q->where('booker_user_id', $gone->id))->value('skip_reason'))->toBe('booker_disabled')
        ->and(EventBookingGroup::query()->where('booker_user_id', $next->id)->value('status'))->toBe('confirmed');
});

// G05 — per-booking seat limit, proxy rule, cancel deadline (relative, recomputed).
it('enforces the seat limit, the proxy setting and the cancel deadline', function () {
    $noProxy = EventCategory::query()->create(['name' => '只限本人', 'allow_proxy_booking' => false]);
    $event = s9Event(['booking_capacity' => 10, 'booking_max_seats' => 2], $noProxy);
    $member = User::factory()->create();

    groupBook($event, $member, ['友人'])->assertStatus(422)->assertJsonPath('error.code', 'PROXY_NOT_ALLOWED');
    $noProxy->update(['allow_proxy_booking' => true]);
    groupBook($event, $member, ['友人', '友人2'])->assertStatus(422)->assertJsonPath('error.code', 'BOOKING_LIMIT_EXCEEDED');

    $created = groupBook($event, $member, ['友人'])->assertCreated();
    $event->update(['cancel_offset_minutes' => 7 * 24 * 60 + 60]); // deadline already passed
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/booking-groups/'.$created->json('data.id').'/cancel')
        ->assertStatus(409)->assertJsonPath('error.code', 'CANCEL_DEADLINE_PASSED');

    // Rescheduling a week later recomputes the relative deadline (S9-L02 A).
    $event->update(['starts_at' => now()->addDays(14), 'ends_at' => now()->addDays(14)->addHour()]);
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/booking-groups/'.$created->json('data.id').'/cancel')->assertOk();
});

// G06 — M03 eligibility checked when booking only (D10, D19).
it('requires the category verification when booking but keeps bookings after it ends', function () {
    $type = VerificationType::query()->create(['name' => '學員']);
    $category = EventCategory::query()->create(['name' => '學員課程', 'required_verification_type_id' => $type->id, 'allow_proxy_booking' => true]);
    $event = s9Event([], $category);
    $member = User::factory()->create();

    groupBook($event, $member)->assertStatus(403)->assertJsonPath('error.code', 'ELIGIBILITY_FAILED');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")->assertStatus(403);

    $badge = MemberVerification::query()->create(['user_id' => $member->id, 'verification_type_id' => $type->id, 'granted_at' => now()]);
    groupBook($event, $member)->assertCreated();

    $badge->forceFill(['revoked_at' => now()])->save();
    expect(EventBookingGroup::query()->where('booker_user_id', $member->id)->value('status'))->toBe('confirmed');
});

// G11 — retries with the same client_request_id never create a second booking.
it('is idempotent for retried single-member requests', function () {
    $event = s9Event();
    $member = User::factory()->create();
    $id = (string) Str::uuid();

    $first = groupBook($event, $member, [], true, $id)->assertCreated()
        ->assertJsonPath('data.seat_count', 1)
        ->assertJsonCount(1, 'data.attendees');
    groupBook($event, $member, [], true, $id)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    // Replays cannot change the booking payload or its member identity.
    $other = User::factory()->create();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $id,
        'member_user_id' => $other->id,
    ])->assertStatus(403)->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');

    $member->update(['name' => '會員新顯示名稱']);
    groupBook($event, $member, [], true, $id)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    groupBook($event, $other, [], true, $id)->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

    expect(EventBookingGroup::query()->count())->toBe(1)
        ->and(seats($event))->toBe(1);
});

// An audit write must commit or roll back together with the booking, so a retry
// after an audit DB failure cannot find a booking with no corresponding audit.
it('rolls back the booking and frees its idempotency key when the audit insert fails', function () {
    $event = s9Event();
    $member = User::factory()->create();
    $missingActor = User::factory()->make(['id' => 999999999]);
    $id = (string) Str::uuid();
    $request = fn (User $actor) => new BookingRequest(
        booker: $member,
        contactName: $member->name,
        contactEmail: $member->email,
        contactPhone: null,
        attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
        clientRequestId: $id,
        actor: $actor,
    );

    expect(fn () => app(BookingEngine::class)->book($event, $request($missingActor)))
        ->toThrow(QueryException::class);
    expect(EventBookingGroup::query()->where('client_request_id', $id)->count())->toBe(0)
        ->and(EventBookingAttendee::query()->where('calendar_event_id', $event->id)->count())->toBe(0)
        ->and(seats($event))->toBe(0);

    app(BookingEngine::class)->book($event, $request($member));
    expect(EventBookingGroup::query()->where('client_request_id', $id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event', 'booking.confirmed')->count())->toBe(1)
        ->and(seats($event))->toBe(1);
});

// G12 / G13 / G14 — admin proxy booking, capacity reduction with chosen cancellations, event cancel.
it('lets admins reduce capacity only by choosing whom to cancel', function () {
    $event = s9Event(['booking_capacity' => 4]);
    $admin = makeAdmin();
    $a = User::factory()->create();
    $b = User::factory()->create();
    groupBook($event, $a, ['A2']);
    groupBook($event, $b, ['B2']);
    $engine = app(BookingEngine::class);

    expect(fn () => $engine->changeCapacity($event, 3, [], $admin))->toThrow(ApiException::class);
    expect($event->refresh()->booking_capacity)->toBe(4);

    $groupB = EventBookingGroup::query()->where('booker_user_id', $b->id)->firstOrFail();
    $engine->changeCapacity($event, 3, [$groupB->id], $admin, '場地縮小');

    expect($event->refresh()->booking_capacity)->toBe(3)
        ->and($groupB->refresh()->status)->toBe('cancelled')
        ->and(seats($event))->toBe(2)
        ->and(AuditEvent::query()->where('event', 'event.capacity_changed')->sole()->details['cancelled_groups'])->toBe([$groupB->id])
        ->and($b->notifications()->first()->data['body'])->toContain('場地縮小');
});

it('cancels the whole occurrence without promoting anyone and notifies every contact', function () {
    $event = s9Event(['booking_capacity' => 1]);
    $admin = makeAdmin();
    $confirmed = User::factory()->create();
    $waiting = User::factory()->create();
    groupBook($event, $confirmed);
    groupBook($event, $waiting);

    app(BookingEngine::class)->cancelEvent($event, '颱風', $admin);

    expect($event->refresh()->status)->toBe(CalendarEvent::STATUS_CANCELLED)
        ->and(EventBookingGroup::query()->pluck('status')->unique()->values()->all())->toBe(['event_cancelled'])
        ->and(EventWaitlistEntry::query()->value('status'))->toBe('cancelled')
        ->and(seats($event))->toBe(0)
        ->and($confirmed->notifications()->count())->toBe(1)
        ->and($waiting->notifications()->first()->data['title'])->toContain('活動取消');
});

it('lets admins reorder the waitlist with an audited reason', function () {
    $event = s9Event(['booking_capacity' => 1]);
    $admin = makeAdmin();
    groupBook($event, User::factory()->create());
    $first = User::factory()->create();
    $second = User::factory()->create();
    groupBook($event, $first);
    groupBook($event, $second);
    $g2 = EventBookingGroup::query()->where('booker_user_id', $second->id)->firstOrFail();

    app(BookingEngine::class)->moveInWaitlist($g2, 1, $admin, '特殊需求');

    $order = EventWaitlistEntry::query()->where('status', 'waiting')->orderBy('sequence')->pluck('event_booking_group_id')->all();
    $audit = AuditEvent::query()->where('event', 'booking.waitlist_reordered')->sole();
    expect($order[0])->toBe($g2->id)
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->details['reason'])->toBe('特殊需求')
        ->and($audit->details['after'][0])->toBe($g2->id);
});

it('lists my booking groups and keeps others private', function () {
    $event = s9Event();
    $member = User::factory()->create();
    groupBook($event, $member, ['友人']);
    $other = User::factory()->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.attendees.0.is_me', true)->assertJsonPath('data.0.can_cancel', true);
    $id = EventBookingGroup::query()->value('id');
    $this->actingAs($other, 'sanctum')->postJson("/api/v1/me/booking-groups/{$id}/cancel")->assertNotFound();
});

// Migration backfill (G01): idempotent and verified.
it('backfills S6 bookings into the ledger once', function () {
    $event = s9Event(['booking_capacity' => 5]);
    $member = User::factory()->create();
    $booking = app(BookingService::class)->book($event, $member);
    // Simulate a pre-S9 row: remove its mapping, then run the backfill twice.
    DB::table('event_booking_attendees')->delete();
    DB::table('event_booking_groups')->delete();

    expect(LegacyBookingBackfill::run())->toBe(1)
        ->and(LegacyBookingBackfill::run())->toBe(0)
        ->and(EventBookingGroup::query()->sole()->legacy_booking_id)->toBe($booking->id)
        ->and(seats($event))->toBe(1);
});
