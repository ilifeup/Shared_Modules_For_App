<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('lists only active delegations belonging to the logged-in manager', function () {
    $manager = User::factory()->create();
    $otherManager = User::factory()->create();
    $member = User::factory()->create();
    $otherMember = User::factory()->create();
    $now = now();

    DB::table('family_memberships')->insert([
        ['manager_user_id' => $manager->id, 'member_user_id' => $member->id,
            'status' => 'active', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['manager_user_id' => $otherManager->id, 'member_user_id' => $otherMember->id,
            'status' => 'active', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/family-memberships')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.member_user_id', $member->id);
});

it('revokes only the current managers own delegation and writes an audit', function () {
    $manager = User::factory()->create();
    $stranger = User::factory()->create();
    $member = User::factory()->create();
    $now = now();
    $id = DB::table('family_memberships')->insertGetId([
        'manager_user_id' => $manager->id, 'member_user_id' => $member->id,
        'status' => 'active', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $url = "/api/v1/me/family-memberships/{$id}/revoke";

    $this->actingAs($stranger, 'sanctum')->postJson($url)->assertNotFound();
    $this->actingAs($manager, 'sanctum')->postJson($url)
        ->assertOk()->assertJsonPath('data.status', 'revoked');
    expect(DB::table('family_memberships')->where('id', $id)->value('status'))->toBe('revoked')
        ->and(DB::table('family_membership_audits')->where('membership_id', $id)->where('action', 'revoked')->count())->toBe(1);

    $this->actingAs($manager, 'sanctum')->postJson($url)->assertNotFound();
});

it('keeps historical bookings but blocks cancellation after delegation revocation', function () {
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $event = s9Event(['booking_capacity' => 5, 'booking_max_seats' => 5]);
    $now = now();
    $membershipId = DB::table('family_memberships')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_user_id' => $member->id,
        'status' => 'active',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $booking = $this->actingAs($manager, 'sanctum')->postJson("/api/v1/events/{$event->id}/booking-groups", [
        'include_self' => false,
        'attendees' => [['member_user_id' => $member->id]],
        'client_request_id' => (string) Str::uuid(),
    ])->assertCreated();

    $groupId = $booking->json('data.id');
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/me/family-memberships/{$membershipId}/revoke")
        ->assertOk();

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/me/booking-groups/{$groupId}/cancel")
        ->assertForbidden()->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');

    expect(DB::table('event_booking_groups')->where('id', $groupId)->value('status'))->toBe('confirmed');
});

it('rejects idempotent booking replay after family delegation is revoked', function () {
    $manager = User::factory()->create();
    $member = User::factory()->create();
    $event = s9Event(['booking_capacity' => 5, 'booking_max_seats' => 5]);
    $now = now();
    $membershipId = DB::table('family_memberships')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_user_id' => $member->id,
        'status' => 'active',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $payload = [
        'include_self' => false,
        'attendees' => [['member_user_id' => $member->id]],
        'client_request_id' => (string) Str::uuid(),
    ];
    $url = "/api/v1/events/{$event->id}/booking-groups";
    $this->actingAs($manager, 'sanctum')->postJson($url, $payload)->assertCreated();
    $this->actingAs($manager, 'sanctum')->postJson($url, $payload)->assertOk();
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/me/family-memberships/{$membershipId}/revoke")
        ->assertOk();
    $this->actingAs($manager, 'sanctum')->postJson($url, $payload)
        ->assertForbidden()->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');
    expect(DB::table('event_booking_groups')->where('client_request_id', $payload['client_request_id'])->count())->toBe(1);
});
