<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

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
