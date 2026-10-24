<?php

use App\Models\User;

it('returns the own profile with approved fields only', function () {
    $user = User::factory()->create(['display_name' => '小明']);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/profile')
        ->assertOk()
        ->assertJsonPath('data.id', (string) $user->id)
        ->assertJsonPath('data.display_name', '小明')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');
});

it('updates only the own display name and ignores other fields', function () {
    $user = User::factory()->create(['email' => 'me@example.com', 'display_name' => 'Old']);
    $other = User::factory()->create(['display_name' => 'Other']);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', [
        'display_name' => '  新名字  ',
        'id' => $other->id,
        'email' => 'hijack@example.com',
        'is_active' => false,
    ])->assertOk()->assertJsonPath('data.display_name', '新名字');

    expect($user->refresh()->email)->toBe('me@example.com');
    expect($user->is_active)->toBeTrue();
    expect($other->refresh()->display_name)->toBe('Other');
});

it('rejects an invalid display name', function () {
    $user = User::factory()->create(['display_name' => 'Keep']);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['display_name' => str_repeat('長', 81)])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['display_name']]]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['display_name' => ''])->assertStatus(422);

    expect($user->refresh()->display_name)->toBe('Keep');
});
