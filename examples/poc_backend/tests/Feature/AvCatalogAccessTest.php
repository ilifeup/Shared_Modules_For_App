<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvCatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_music_or_video_catalog(): void
    {
        $this->getJson('/api/v1/av/tracks')->assertUnauthorized();
        $this->getJson('/api/v1/av/videos')->assertUnauthorized();
    }

    public function test_unverified_member_cannot_access_music_or_video_catalog(): void
    {
        $member = User::factory()->unverified()->create([
            'phone_e164' => null,
            'phone_verified_at' => null,
        ]);
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();
    }

    public function test_verified_member_receives_empty_catalog_without_grants(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJson(['data' => []]);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJson(['data' => []]);
    }
}
