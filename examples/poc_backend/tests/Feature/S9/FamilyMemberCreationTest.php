<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates a verified non-login family member with an independent user id', function () {
    $manager = User::factory()->create(['phone_e164' => '+886912345678']);
    $applicationId = DB::table('family_member_applications')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_name' => 'Family Child',
        'relationship' => 'child',
        'birth_date' => '2015-06-01',
        'address' => 'Home address',
        'contact_phone' => '+886912345678',
        'contact_email' => $manager->email,
        'evidence_path' => 'family-evidence/approved.pdf',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $response = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/me/family-memberships', [
            'application_id' => $applicationId,
        ])->assertCreated()->assertJsonPath('data.FamilyUser', true)
        ->assertJsonPath('data.verified_status', 2);

    $id = $response->json('data.member_user_id');
    expect((int) $id)->not->toBe($manager->id);
    $member = User::query()->findOrFail($id);
    expect($member->FamilyUser)->toBeTrue()
        ->and($member->verified_status)->toBe(2)
        ->and($member->email)->toBeNull()
        ->and($member->phone_e164)->toBeNull()
        ->and($member->password)->toBeNull();
    expect(DB::table('family_member_profiles')->where('member_user_id', $id)->value('contact_email'))->toBe($manager->email);
    expect(DB::table('family_memberships')->where('member_user_id', $id)->value('manager_user_id'))->toBe($manager->id);
});

it('does not create a family member for an unverified manager', function () {
    $manager = User::factory()->unverified()->create();
    $before = User::query()->count();

    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'name' => 'Child',
        'birth_date' => '2015-06-01',
        'address' => 'Home address',
    ])->assertForbidden();

    expect(User::query()->count())->toBe($before);
});

it('rejects managed family identities from manager-only family APIs even with a forged authenticated session', function () {
    $familyUser = User::factory()->create();
    $familyUser->forceFill(['FamilyUser' => true, 'verified_status' => 2])->save();

    $this->actingAs($familyUser, 'sanctum')
        ->getJson('/api/v1/me/family-memberships')->assertForbidden();

    $this->actingAs($familyUser, 'sanctum')
        ->putJson('/api/v1/me/family-members/'.$familyUser->id.'/profile', [
            'birth_date' => '2015-06-01',
            'address' => 'Test address',
        ])->assertForbidden();
});

it('does not create a family member before administrator approval', function () {
    $manager = User::factory()->create();
    $applicationId = DB::table('family_member_applications')->insertGetId([
        'manager_user_id' => $manager->id,
        'member_name' => 'Pending Child',
        'relationship' => 'child',
        'birth_date' => '2015-06-01',
        'address' => 'Home address',
        'evidence_path' => 'family-evidence/pending.pdf',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = User::query()->count();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/me/family-memberships', [
            'application_id' => $applicationId,
        ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_APPROVAL_REQUIRED');

    expect(User::query()->count())->toBe($before);
    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(0);
});

it('allows a manager to edit only their own family profile without changing identity or login status', function () {
    $manager = User::factory()->create(['verified_status' => 2]);
    $otherManager = User::factory()->create(['verified_status' => 2]);
    $family = User::factory()->create(['FamilyUser' => true, 'verified_status' => 2, 'password' => null]);
    $otherFamily = User::factory()->create(['FamilyUser' => true, 'verified_status' => 2]);
    $now = now();

    DB::table('family_memberships')->insert([
        ['manager_user_id' => $manager->id, 'member_user_id' => $family->id,
            'status' => 'active', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['manager_user_id' => $otherManager->id, 'member_user_id' => $otherFamily->id,
            'status' => 'active', 'started_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $payload = [
        'birth_date' => '2015-06-01',
        'address' => 'Updated family address',
        'contact_phone' => '0912345678',
        'contact_email' => 'family-contact@example.test',
    ];

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/me/family-members/{$family->id}/profile", $payload)
        ->assertOk()->assertJsonPath('data.address', 'Updated family address');

    $this->assertDatabaseHas('family_member_profiles', [
        'member_user_id' => $family->id,
        'address' => 'Updated family address',
        'contact_email' => 'family-contact@example.test',
    ]);

    $family->refresh();
    expect($family->id)->toBe($family->getKey())
        ->and($family->FamilyUser)->toBeTrue()
        ->and((int) $family->verified_status)->toBe(2)
        ->and($family->password)->toBeNull();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/me/family-members/{$otherFamily->id}/profile", $payload)
        ->assertForbidden()->assertJsonPath('error.code', 'FAMILY_MEMBER_NOT_AUTHORIZED');

    $this->assertDatabaseMissing('family_member_profiles', [
        'member_user_id' => $otherFamily->id,
        'address' => 'Updated family address',
    ]);
});
