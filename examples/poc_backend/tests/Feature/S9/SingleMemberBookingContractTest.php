<?php

use App\Models\EventBookingGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('books exactly one seat for the verified member', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated()->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.seat_count', 1)
        ->assertJsonCount(1, 'data.attendees');

    expect(seats($event))->toBe(1);
});

it('books one seat for a delegated family member without booking the manager', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $manager = User::factory()->create();
    $member = User::factory()->create([
        'FamilyUser' => true,
        'email' => null,
        'email_verified_at' => null,
        'phone_e164' => null,
        'verified_status' => 2,
    ]);
    DB::table('family_memberships')->insert([
        'member_user_id' => $member->id,
        'manager_user_id' => $manager->id,
        'relationship' => 'child',
        'status' => 'active',
        'started_at' => now(),
        'created_by' => $manager->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'member_user_id' => $member->id,
    ])->assertCreated()->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.seat_count', 1)
        ->assertJsonCount(1, 'data.attendees');

    expect(seats($event))->toBe(1);
    expect(DB::table('event_booking_attendees')->where('member_user_id', $member->id)->count())->toBe(1);
    expect(DB::table('event_booking_attendees')->where('member_user_id', $manager->id)->count())->toBe(0);
});

it('keeps a family booking in the original bookers history without changing the attendee', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'member_user_id' => $member->id, 'manager_user_id' => $manager->id,
        'relationship' => 'child', 'status' => 'active', 'started_at' => now(),
        'created_by' => $manager->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $response = $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => (string) Str::uuid(),
            'member_user_id' => $member->id,
        ])->assertCreated();
    $groupId = $response->json('data.id');

    $this->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $groupId)
        ->assertJsonPath('data.0.seat_count', 1);
    expect(DB::table('event_booking_groups')->where('id', $groupId)
        ->value('booker_user_id'))->toBe($manager->id);
    expect(DB::table('event_booking_attendees')->where('event_booking_group_id', $groupId)
        ->value('member_user_id'))->toBe($member->id);
});

it('rejects a forged family delegation pointing to an ordinary member', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $ordinary = User::factory()->create(['verified_status' => 2, 'FamilyUser' => false]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id, 'member_user_id' => $ordinary->id,
        'relationship' => 'child', 'status' => 'active', 'started_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'member_user_id' => $ordinary->id,
    ])->assertForbidden()->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');

    expect(seats($event))->toBe(0);
});

it('rejects obsolete multi-attendee payloads instead of silently booking a subset', function () {
    $event = s9Event();
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'include_self' => true,
        'attendees' => [['member_user_id' => $member->id]],
    ])->assertUnprocessable();

    expect(seats($event))->toBe(0);
});

it('rejects unknown guest and group keys without creating a booking', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2]);

    foreach ([
        ['seat_count' => 2],
        ['guest_name' => 'Guest'],
        ['guest_count' => 1],
        ['booking_max_seats' => 2],
        ['unexpected_future_field' => 'unsafe'],
    ] as $extra) {
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/booking-groups", array_merge([
                'client_request_id' => (string) Str::uuid(),
            ], $extra))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($extra));
    }

    expect(seats($event))->toBe(0);
    expect(EventBookingGroup::query()->where('calendar_event_id', $event->id)->count())->toBe(0);
});

it('rejects legacy guest and multi-member requests at the shared engine boundary', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $booker = User::factory()->create(['verified_status' => 2]);
    $second = User::factory()->create(['verified_status' => 2]);

    foreach ([
        [null, [['name' => 'Visitor', 'member_user_id' => null]], 403, 'GUEST_NOT_ALLOWED'],
        [$booker, [
            ['name' => $booker->name, 'member_user_id' => $booker->id],
            ['name' => $second->name, 'member_user_id' => $second->id],
        ], 422, 'SINGLE_ATTENDEE_ONLY'],
    ] as [$actor, $attendees, $status, $code]) {
        try {
            app(\App\Services\Booking\BookingEngine::class)->book(
                $event,
                new \App\Services\Booking\BookingRequest(
                    booker: $actor,
                    contactName: $actor?->name ?? 'Visitor',
                    contactEmail: $actor?->email,
                    contactPhone: $actor?->phone_e164,
                    attendees: $attendees,
                    clientRequestId: (string) Str::uuid(),
                    actor: $actor,
                ),
            );
            $this->fail('An unsupported guest or group booking was accepted.');
        } catch (\App\Support\Api\ApiException $e) {
            expect($e->status)->toBe($status)->and($e->errorCode)->toBe($code);
        }
    }

    expect(seats($event))->toBe(0);
    expect(EventBookingGroup::query()->where('calendar_event_id', $event->id)->count())->toBe(0);
});

it('rejects non-integer member identities even for internal booking calls', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2]);

    foreach (['1.5', '1e2', 0, -1, null] as $invalidId) {
        try {
            app(\App\Services\Booking\BookingEngine::class)->book(
                $event,
                new \App\Services\Booking\BookingRequest(
                    booker: $member,
                    contactName: $member->name,
                    contactEmail: $member->email,
                    contactPhone: $member->phone_e164,
                    attendees: [['name' => $member->name, 'member_user_id' => $invalidId]],
                    clientRequestId: (string) Str::uuid(),
                    actor: $member,
                ),
            );
            $this->fail('Malformed member ID was accepted.');
        } catch (\App\Support\Api\ApiException $e) {
            expect($e->status)->toBe(422)->and($e->errorCode)->toBe('MEMBER_ID_REQUIRED');
        }
    }

    expect(seats($event))->toBe(0);
    expect(EventBookingGroup::query()->where('calendar_event_id', $event->id)->count())->toBe(0);
});

it('rejects malformed member IDs on idempotent replay without disclosing the original booking', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2]);
    $key = (string) Str::uuid();

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => $key,
        ])->assertCreated();

    foreach ([(string) $member->id.'.0', (string) $member->id.'e0'] as $invalidId) {
        try {
            app(\App\Services\Booking\BookingEngine::class)->book(
                $event,
                new \App\Services\Booking\BookingRequest(
                    booker: $member,
                    contactName: $member->name,
                    contactEmail: $member->email,
                    contactPhone: $member->phone_e164,
                    attendees: [['name' => $member->name, 'member_user_id' => $invalidId]],
                    clientRequestId: $key,
                    actor: $member,
                ),
            );
            $this->fail('Malformed member ID replay was accepted.');
        } catch (\App\Support\Api\ApiException $e) {
            expect($e->status)->toBe(409)->and($e->errorCode)->toBe('IDEMPOTENCY_CONFLICT');
        }
    }

    expect(seats($event))->toBe(1);
    expect(EventBookingGroup::query()->where('calendar_event_id', $event->id)->count())->toBe(1);
});

it('rejects an unverified member before booking or replay', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 1]);
    $key = (string) Str::uuid();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $key,
    ])->assertForbidden();

    expect(seats($event))->toBe(0);

    $member->forceFill(['verified_status' => 2])->save();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $key,
    ])->assertCreated();

    $member->forceFill(['verified_status' => 1])->save();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $key,
    ])->assertForbidden();

    expect(seats($event))->toBe(1);
});

it('rejects a disabled member even when reusing a valid booking request id', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $key = (string) Str::uuid();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $key,
    ])->assertCreated();

    $member->update(['is_active' => false]);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => $key,
    ])->assertForbidden();

    expect(seats($event))->toBe(1);
});

it('does not promote a waitlisted attendee whose event ledger was corrupted', function () {
    $event = s9Event(['booking_capacity' => 1, 'waitlist_enabled' => true]);
    $unrelated = s9Event();
    $firstMember = User::factory()->create(['verified_status' => 2]);
    $waitingMember = User::factory()->create(['verified_status' => 2]);

    $first = $this->actingAs($firstMember, 'sanctum')
        ->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');
    $waiting = $this->actingAs($waitingMember, 'sanctum')
        ->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.status', 'waitlisted')->json('data.id');

    // Simulate a stale or corrupt historical ledger before capacity is released.
    DB::table('event_booking_attendees')->where('event_booking_group_id', $waiting)
        ->update(['calendar_event_id' => $unrelated->id]);

    $this->actingAs($firstMember, 'sanctum')
        ->postJson("/api/v1/me/booking-groups/{$first}/cancel")
        ->assertOk();

    expect(EventBookingGroup::query()->findOrFail($waiting)->status)->toBe('cancelled');
    expect(seats($event))->toBe(0);
});

it('cancels a delegated booking once and refuses a stale second cancellation', function () {
    $event = s9Event(['booking_capacity' => 1, 'waitlist_enabled' => true]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $family = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id, 'member_user_id' => $family->id,
        'relationship' => 'child', 'status' => 'active',
        'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $first = $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(), 'member_user_id' => $family->id,
    ])->assertCreated();
    $other = User::factory()->create(['verified_status' => 2]);
    $waiting = $this->actingAs($other, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated()->assertJsonPath('data.status', 'waitlisted');

    $url = '/api/v1/me/booking-groups/'.$first->json('data.id').'/cancel';
    $this->actingAs($manager, 'sanctum')->postJson($url)->assertOk();
    $this->postJson($url)->assertStatus(409)->assertJsonPath('error.code', 'BOOKING_NOT_ACTIVE');
    expect(seats($event))->toBe(1);
    expect(DB::table('event_booking_groups')->where('id', $waiting->json('data.id'))->value('status'))->toBe('confirmed');
});

it('never permits a managed family member to book directly even with a forged Sanctum session', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $family = User::factory()->create([
        'FamilyUser' => true, 'verified_status' => 2, 'is_active' => true,
        'email' => null, 'phone_e164' => null,
    ]);

    $this->actingAs($family, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'member_user_id' => $family->id,
    ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_LOGIN_FORBIDDEN');

    expect(seats($event))->toBe(0);
    expect(DB::table('event_booking_groups')->count())->toBe(0);
});

it('creates separate single-seat bookings when one manager books for two family members', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $familyIds = [];
    foreach (range(1, 2) as $number) {
        $family = User::factory()->create(['verified_status' => 2, 'is_active' => true, 'FamilyUser' => true]);
        $familyIds[] = $family->id;
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id, 'member_user_id' => $family->id,
            'relationship' => 'child', 'status' => 'active', 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => (string) Str::uuid(),
            'member_user_id' => $family->id,
        ])->assertCreated()->assertJsonPath('data.seat_count', 1)
            ->assertJsonCount(1, 'data.attendees');
    }

    $groups = DB::table('event_booking_groups')->where('calendar_event_id', $event->id)->get();
    expect($groups)->toHaveCount(2);
    expect($groups->pluck('booker_user_id')->unique()->all())->toBe([$manager->id]);
    expect($groups->pluck('seat_count')->all())->toBe([1, 1]);
    expect(DB::table('event_booking_attendees')->where('calendar_event_id', $event->id)
        ->orderBy('id')->pluck('member_user_id')->all())->toBe($familyIds);
    expect(seats($event))->toBe(2);
});

it('rejects a second active booking for the same member even with a new request key', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $member = User::factory()->create(['verified_status' => 2]);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated();

    $this->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
    ])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_BOOKED');

    expect(DB::table('event_booking_groups')->where('calendar_event_id', $event->id)->count())->toBe(1);
    expect(DB::table('event_booking_attendees')->where('calendar_event_id', $event->id)->count())->toBe(1);
    expect(seats($event))->toBe(1);
});

it('resolves the new single-attendee relation for a member booking', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2]);
    $response = $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated();

    $booking = EventBookingGroup::query()->findOrFail($response->json('data.id'));
    expect($booking->attendee)->not->toBeNull();
    expect((int) $booking->attendee->member_user_id)->toBe($member->id);
    expect($booking->attendees()->count())->toBe(1);
});

it('replays the same member request without adding seats and rejects another member on the same key', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $family = [];
    foreach (range(1, 2) as $index) {
        $member = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $member->id,
            'relationship' => 'child',
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $family[] = $member;
    }

    $key = (string) Str::uuid();
    $url = "/api/v1/events/{$event->id}/booking-groups";
    $payload = ['client_request_id' => $key, 'member_user_id' => $family[0]->id];
    $first = $this->actingAs($manager, 'sanctum')->postJson($url, $payload)->assertCreated();
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson($url, [
        'client_request_id' => $key,
        'member_user_id' => $family[1]->id,
    ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

    expect(DB::table('event_booking_groups')->where('calendar_event_id', $event->id)->count())->toBe(1);
    expect(DB::table('event_booking_attendees')->where('calendar_event_id', $event->id)
        ->pluck('member_user_id')->all())->toBe([$family[0]->id]);
    expect(seats($event))->toBe(1);
});

it('limits delegated family booking history to two months without hiding own bookings', function () {
    $event = s9Event(['booking_capacity' => 3]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $family = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id,
        'member_user_id' => $family->id,
        'status' => 'active',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $url = "/api/v1/events/{$event->id}/booking-groups";
    $this->actingAs($manager, 'sanctum')->postJson($url, [
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated();

    $familyEvent = s9Event(['booking_capacity' => 3]);
    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$familyEvent->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'member_user_id' => $family->id,
    ])->assertCreated();

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 2);

    DB::table('event_booking_groups')->where('booker_user_id', $manager->id)
        ->update(['booked_at' => now()->subMonths(3)]);

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 1);

    DB::table('family_memberships')->where('manager_user_id', $manager->id)
        ->update(['status' => 'ended', 'ended_at' => now()]);

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('preserves recent original-booker family history after delegation or verification is revoked', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $manager = User::factory()->create(['verified_status' => 2]);
    $family = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id,
        'member_user_id' => $family->id,
        'status' => 'active',
        'started_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'client_request_id' => (string) Str::uuid(),
        'member_user_id' => $family->id,
    ])->assertCreated();

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 1);

    DB::table('family_memberships')->where('manager_user_id', $manager->id)
        ->where('member_user_id', $family->id)
        ->update(['status' => 'ended', 'ended_at' => now()]);

    $family->forceFill(['verified_status' => 0])->save();

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/booking-groups')
        ->assertOk()->assertJsonPath('meta.total', 1);

    // Read-only history remains visible; changing/cancelling it still requires
    // the current family delegation.
    $group = EventBookingGroup::query()->where('booker_user_id', $manager->id)->firstOrFail();
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/me/booking-groups/{$group->id}/cancel")
        ->assertStatus(403);
});

it('rejects legacy S6 booking on series-owned course occurrences', function () {
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $category = DB::table('course_categories')->insertGetId([
        'name' => 'Series guard test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $group = DB::table('course_groups')->insertGetId([
        'course_category_id' => $category, 'name' => 'Series guard',
        'capacity' => 2, 'waitlist_capacity' => 2, 'expected_event_count' => 1,
        'price_amount' => '100.00', 'payment_hold_hours' => 24,
        'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $term = DB::table('course_terms')->insertGetId([
        'title' => 'Term guard', 'price_amount' => 1,
        'enrollment_capacity' => 2, 'waitlist_capacity' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $groupEvent = s9Event(['booking_capacity' => 2]);
    $termEvent = s9Event(['booking_capacity' => 2]);
    DB::table('course_group_events')->insert([
        'course_group_id' => $group, 'calendar_event_id' => $groupEvent->id,
        'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('course_sessions')->insert([
        'course_term_id' => $term, 'calendar_event_id' => $termEvent->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ([$groupEvent, $termEvent] as $event) {
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/bookings")
            ->assertStatus(409)->assertJsonPath('error.code', 'SERIES_BOOKING_REQUIRED');
        expect(DB::table('event_booking_attendees')
            ->where('calendar_event_id', $event->id)->count())->toBe(0);
        expect(DB::table('event_bookings')
            ->where('calendar_event_id', $event->id)->count())->toBe(0);
    }
});


it('filters event-cancelled bookings explicitly without changing the cancelled aggregate', function () {
    $event = s9Event(['booking_capacity' => 2]);
    $member = User::factory()->create(['verified_status' => 2]);
    $groupId = $this->actingAs($member, 'sanctum')
        ->postJson("/api/v1/events/{$event->id}/booking-groups", [
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

    EventBookingGroup::query()->whereKey($groupId)->update(['status' => EventBookingGroup::STATUS_EVENT_CANCELLED]);

    $this->getJson('/api/v1/me/booking-groups?status=event_cancelled')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $groupId)
        ->assertJsonPath('data.0.status', 'event_cancelled');

    $this->getJson('/api/v1/me/booking-groups?status=cancelled')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $groupId);
});
