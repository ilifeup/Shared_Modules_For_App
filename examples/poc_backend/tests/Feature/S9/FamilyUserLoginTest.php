<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('rejects a managed family member password login even with correct credentials', function () {
    $member = User::factory()->create([
        'FamilyUser' => true,
        'verified_status' => 2,
        'password' => Hash::make('family-password-123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'identifier' => $member->email,
        'password' => 'family-password-123',
    ])->assertForbidden()->assertJsonPath('error.code', 'FAMILY_LOGIN_FORBIDDEN');
});

it('creates an independent user ID on ordinary registration regardless of optional family details', function () {
    $managed = User::factory()->create([
        'FamilyUser' => true,
        'verified_status' => 2,
        'name' => 'Existing Family Member',
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'New Independent Member',
        'email' => 'new-independent@example.com',
        'phone' => '0912345678',
        'password' => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'family_name' => 'Existing Family Member',
        'family_relationship' => 'child',
    ])->assertCreated();

    $newId = $response->json('data.user.id');
    expect((int) $newId)->not->toBe($managed->id);
    expect((bool) User::query()->findOrFail($newId)->FamilyUser)->toBeFalse();
    expect((int) User::query()->findOrFail($newId)->verified_status)->toBe(1);
    expect((int) User::query()->findOrFail($managed->id)->verified_status)->toBe(2);
});
