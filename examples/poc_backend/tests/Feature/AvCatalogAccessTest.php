<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
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
        app(Settings::class)->put(['verification_policy' => Settings::POLICY_EMAIL]);
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

    public function test_configured_page_or_list_denial_blocks_catalog_without_changing_legacy_default(): void
    {
        $member = User::factory()->create();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/av/tracks')->assertOk();

        \Illuminate\Support\Facades\DB::table('app_content_access_rules')->insert([
            'scope' => 'page', 'resource_code' => 'av',
            'audience' => 'register_member', 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();

        \Illuminate\Support\Facades\DB::table('app_content_access_rules')->where('resource_code', 'av')->update(['allowed' => true]);
        $this->getJson('/api/v1/av/tracks')->assertOk();

        \Illuminate\Support\Facades\DB::table('app_content_access_rules')->insert([
            'scope' => 'list', 'resource_code' => 'av/music',
            'audience' => 'member', 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertOk();
    }
}
