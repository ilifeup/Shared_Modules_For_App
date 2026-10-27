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
        ->and($member->phone_e164)->toBeNull();
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
