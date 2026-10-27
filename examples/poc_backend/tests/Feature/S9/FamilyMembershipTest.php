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

it('replays by member ID after a delegated attendee changes their display name', function () {
    $manager = User::factory()->create();
    $member = User::factory()->create(['name' => '原姓名']);
    $event = s9Event(['booking_capacity' => 5, 'booking_max_seats' => 5]);
    $now = now();
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id,
        'member_user_id' => $member->id,
        'status' => 'active',
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $payload = [
        'member_user_id' => $member->id,
        'client_request_id' => (string) Str::uuid(),
    ];
    $url = "/api/v1/events/{$event->id}/booking-groups";
    $first = $this->actingAs($manager, 'sanctum')->postJson($url, $payload)->assertCreated();
    $member->update(['name' => '更新後姓名']);

    $this->actingAs($manager, 'sanctum')->postJson($url, $payload)
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

    expect(DB::table('event_booking_groups')->where('client_request_id', $payload['client_request_id'])->count())->toBe(1)
        ->and(DB::table('event_booking_attendees')->where('event_booking_group_id', $first->json('data.id'))->value('name'))->toBe('原姓名');
});

it('does not reveal whether an unauthorized family member ID exists', function () {
    $manager = User::factory()->create();
    $stranger = User::factory()->create();
    $event = s9Event(['booking_capacity' => 5, 'booking_max_seats' => 5]);
    $url = "/api/v1/events/{$event->id}/booking-groups";

    foreach ([$stranger->id, 999999999] as $memberId) {
        $this->actingAs($manager, 'sanctum')->postJson($url, [
            'member_user_id' => $memberId,
            'client_request_id' => (string) Str::uuid(),
        ])->assertForbidden()->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');
    }

    expect(DB::table('event_booking_groups')->count())->toBe(0);
});

it('rejects the forbidden client family unlink endpoint', function () {
    $manager = User::factory()->create();
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/me/family-memberships/1/revoke')
        ->assertNotFound();
});

it('creates only three free verified family members and rejects a fourth', function () {
    $manager = User::factory()->create(['verified_status' => 2]);
    $url = '/api/v1/me/family-memberships';
    $payload = [
        'name' => '家庭會員', 'relationship' => 'child',
        'birth_date' => '2010-01-01', 'address' => '台中市',
    ];
    $this->actingAs($manager, 'sanctum');
    for ($i = 1; $i <= 3; $i++) {
        $response = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.verified_status', 2);
        expect(DB::table('users')->where('id', $response->json('data.member_user_id'))
            ->value('verified_status'))->toBe(2);
    }
    $this->postJson($url, $payload)
        ->assertForbidden()->assertJsonPath('error.code', 'FAMILY_MEMBER_LIMIT_REACHED');
    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(3);
});

