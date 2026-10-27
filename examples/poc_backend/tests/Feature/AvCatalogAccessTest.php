<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        DB::table('app_content_access_rules')->insert([
            'scope' => 'page', 'resource_code' => 'av',
            'audience' => 'register_member', 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();

        DB::table('app_content_access_rules')->where('resource_code', 'av')->update(['allowed' => true]);
        $this->getJson('/api/v1/av/tracks')->assertOk();

        DB::table('app_content_access_rules')->insert([
            'scope' => 'list', 'resource_code' => 'av/music',
            'audience' => 'register_member', 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertOk();
    }

    public function test_guest_only_navigation_rules_do_not_block_verified_member_catalog(): void
    {
        $member = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        Sanctum::actingAs($member);

        DB::table('app_content_access_rules')->insert([
            ['scope' => 'page', 'resource_code' => 'av', 'audience' => 'guest', 'allowed' => false],
            ['scope' => 'list', 'resource_code' => 'av/music', 'audience' => 'guest', 'allowed' => false],
        ]);

        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJson(['data' => []]);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJson(['data' => []]);

        DB::table('app_content_access_rules')->where('audience', 'guest')->update(['allowed' => true]);
        $this->getJson('/api/v1/av/tracks')->assertOk();
        $this->getJson('/api/v1/av/videos')->assertOk();

        DB::table('app_content_access_rules')->insert([
            'scope' => 'page', 'resource_code' => 'av',
            'audience' => 'verified_member', 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();

        DB::table('app_content_access_rules')->where('audience', 'verified_member')->delete();
        DB::table('app_content_access_rules')->insert([
            'scope' => 'list', 'resource_code' => 'av/music',
            'audience' => 'user:'.$member->id, 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertOk();
    }
}
