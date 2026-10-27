<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

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

it('requires administrator approval before any family member account can be created', function () {
    $manager = User::factory()->create(['verified_status' => 2]);
    $payload = [
        'application_id' => 999999,
    ];
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/me/family-memberships', $payload)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FAMILY_APPROVAL_REQUIRED');

    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(0);
    expect(DB::table('users')->where('FamilyUser', true)->count())->toBe(0);
});

it('accepts private evidence applications without creating a family account', function () {
    Storage::fake('local');
    $manager = User::factory()->create(['verified_status' => 2]);
    $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-applications', [
        'name' => '家庭成員',
        'relationship' => 'child',
        'birth_date' => '2010-01-01',
        'address' => '台中市',
        'relationship_evidence' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
    ])->assertCreated()->assertJsonPath('data.status', 'pending');

    $application = DB::table('family_member_applications')->where('id', $response->json('data.id'))->first();
    expect((int) $application->manager_user_id)->toBe((int) $manager->id)
        ->and($application->status)->toBe('pending');
    Storage::disk('local')->assertExists($application->evidence_path);
    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(0);
});

it('rejects a family application without relationship evidence', function () {
    $manager = User::factory()->create(['verified_status' => 2]);
    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-applications', [
        'name' => '家庭成員',
        'relationship' => 'child',
        'birth_date' => '2010-01-01',
        'address' => '台中市',
    ])->assertUnprocessable();
    expect(DB::table('family_member_applications')->count())->toBe(0);
});

it('requires administrator approval and consumes a reviewed application only once', function () {
    $manager = User::factory()->create(['verified_status' => 2]);
    $admin = User::factory()->create(['verified_status' => 2]);
    $admin->assignRole('admin');
    $now = now();
    $id = DB::table('family_member_applications')->insertGetId([
        'manager_user_id' => $manager->id, 'member_name' => '家庭成員',
        'relationship' => 'child', 'birth_date' => '2010-01-01',
        'address' => '台中市', 'evidence_path' => 'family-evidence/test.pdf',
        'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'application_id' => $id,
    ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_APPROVAL_REQUIRED');

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/family-applications/{$id}/review", [
        'decision' => 'approved',
    ])->assertOk()->assertJsonPath('data.status', 'approved');

    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'application_id' => $id,
    ])->assertCreated()->assertJsonPath('data.verified_status', 2);

    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'application_id' => $id,
    ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_APPROVAL_REQUIRED');
    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(1);
});

it('lets only admin configure the maximum while the free allowance stays one', function () {
    $member = User::factory()->create(['verified_status' => 2]);
    $admin = User::factory()->create(['verified_status' => 2]);
    $admin->assignRole('admin');
    $this->actingAs($member, 'sanctum')->putJson('/api/v1/admin/family-settings', [
        'max_members' => 3,
    ])->assertForbidden();
    $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/family-settings', [
        'max_members' => 3,
    ])->assertOk()->assertJsonPath('data.free_members', 1);
    $this->getJson('/api/v1/admin/family-settings')
        ->assertOk()->assertJsonPath('data.max_members', 3)
        ->assertJsonPath('data.free_members', 1);
});

it('requires verified payment before applying for the second family member', function () {
    Storage::fake('local');
    $manager = User::factory()->create(['verified_status' => 2]);
    $admin = User::factory()->create(['verified_status' => 2]);
    $admin->assignRole('admin');
    $family = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id, 'member_user_id' => $family->id,
        'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = [
        'name' => 'Second', 'relationship' => 'child', 'birth_date' => '2012-01-01',
        'address' => 'Home', 'relationship_evidence' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
    ];
    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-applications', $payload)
        ->assertForbidden()->assertJsonPath('error.code', 'FAMILY_PAYMENT_REQUIRED');
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/family-payments/verify', [
        'manager_user_id' => $manager->id, 'payment_reference' => 'test-paid-second-1',
    ])->assertCreated();
    $response = $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-applications', $payload)
        ->assertCreated()->assertJsonPath('data.status', 'pending');
    $id = $response->json('data.id');
    expect(DB::table('family_member_applications')->where('id', $id)->value('payment_verified'))->toBeTruthy();
    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/family-applications/{$id}/review", [
        'decision' => 'approved',
    ])->assertOk();
    $this->actingAs($manager, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'application_id' => $id,
    ])->assertCreated();
    expect(DB::table('family_memberships')->where('manager_user_id', $manager->id)->count())->toBe(2);
});

it('does not allow a family member session to apply for or manage family accounts', function () {
    $family = User::factory()->create(['FamilyUser' => true, 'verified_status' => 2]);
    $this->actingAs($family, 'sanctum')->getJson('/api/v1/me/family-memberships')
        ->assertForbidden()->assertJsonPath('error.code', 'FAMILY_LOGIN_FORBIDDEN');
    $this->actingAs($family, 'sanctum')->getJson('/api/v1/me/family-applications')
        ->assertForbidden()->assertJsonPath('error.code', 'FAMILY_LOGIN_FORBIDDEN');
    $this->actingAs($family, 'sanctum')->postJson('/api/v1/me/family-memberships', [
        'application_id' => 1,
    ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_LOGIN_FORBIDDEN');
});
