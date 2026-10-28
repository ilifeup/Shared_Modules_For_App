<?php

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
