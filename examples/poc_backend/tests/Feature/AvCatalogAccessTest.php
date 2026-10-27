<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_guest_cannot_access_protected_track_content_or_item_authorization(): void
    {
        $this->getJson('/api/v1/av/tracks/1/content')->assertUnauthorized();
        $this->getJson('/api/v1/av/tracks/1/access')->assertUnauthorized();
        $this->getJson('/api/v1/av/videos/1/access')->assertUnauthorized();
    }

    public function test_guest_raw_api_request_returns_json_401_without_login_redirect(): void
    {
        $this->get('/api/v1/av/tracks/1/content')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_private_track_rejects_invalid_persisted_storage_paths(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
        Sanctum::actingAs($member);
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'Invalid path', 'category' => 'test',
            'encrypted_storage_path' => 'av/../unrelated.enc',
            'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => true,
        ]);
        Storage::disk('local')->put('unrelated.enc', 'secret');
        $this->get('/api/v1/av/tracks/'.$trackId.'/content')->assertNotFound();
        DB::table('av_music_tracks')->where('id', $trackId)->update(['encrypted_storage_path' => 'av/']);
        $this->get('/api/v1/av/tracks/'.$trackId.'/content')->assertNotFound();
    }

    public function test_verified_member_without_track_grant_cannot_read_private_bytes(): void
    {
        $member = User::factory()->create(['verified_status' => 2]);
        Sanctum::actingAs($member);
        $this->get('/api/v1/av/tracks/999999/content')->assertForbidden();
        $this->getJson('/api/v1/av/tracks/999999/access')->assertForbidden();
        $this->getJson('/api/v1/av/videos/999999/access')->assertForbidden();
    }

    public function test_private_encrypted_track_requires_live_grant_and_never_leaks_storage_path(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
        Sanctum::actingAs($member);
        $path = 'av/private-test.enc';
        Storage::disk('local')->put($path, 'encrypted-fixture');
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'Private test', 'category' => 'test',
            'encrypted_storage_path' => $path, 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get('/api/v1/av/tracks/'.$trackId.'/content')->assertForbidden();
        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => true,
        ]);
        $this->getJson('/api/v1/av/tracks/'.$trackId.'/access')
            ->assertOk()->assertDontSee('private-test.enc');
        $this->get('/api/v1/av/tracks/'.$trackId.'/content')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        DB::table('av_track_member_access')->where('track_id', $trackId)->update(['allowed' => false]);
        $this->get('/api/v1/av/tracks/'.$trackId.'/content')->assertForbidden();
    }

    public function test_unverified_member_cannot_open_protected_media_even_with_direct_url(): void
    {
        $member = User::factory()->unverified()->create(['verified_status' => 1]);
        Sanctum::actingAs($member);
        $this->get('/api/v1/av/tracks/1/content')->assertForbidden();
        $this->getJson('/api/v1/av/tracks/1/access')->assertForbidden();
        $this->getJson('/api/v1/av/videos/1/access')->assertForbidden();
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
        $member = User::factory()->create(['verified_status' => 2]);
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJson(['data' => []]);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJson(['data' => []]);
    }

    public function test_configured_page_or_list_denial_blocks_catalog_without_changing_legacy_default(): void
    {
        $member = User::factory()->create(['verified_status' => 2]);
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

    public function test_catalog_sql_preserves_explicit_music_deny_and_active_level_grants(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2, 'email_verified_at' => now()]);
        Sanctum::actingAs($member);
        $levelId = DB::table('membership_levels')->insertGetId([
            'code' => 'av_catalog_sql', 'name' => 'Catalog SQL', 'rank' => 97,
            'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('member_level_assignments')->insert([
            'user_id' => $member->id, 'membership_level_id' => $levelId,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'SQL track', 'category' => 'test', 'encrypted_storage_path' => 'av/sql.enc',
            'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'catalog_sql_01', 'title' => 'SQL video',
            'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('av_track_level_access')->insert(['track_id' => $trackId, 'member_level_id' => $levelId]);
        DB::table('av_video_level_access')->insert(['video_id' => $videoId, 'member_level_id' => $levelId]);

        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonPath('data.0.id', $trackId);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonPath('data.0.id', $videoId);

        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => false,
        ]);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonCount(1, 'data');

        DB::table('av_track_member_access')->where('track_id', $trackId)->update(['allowed' => true]);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonCount(1, 'data');

        DB::table('member_level_assignments')->where('user_id', $member->id)->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonCount(0, 'data');

        $member->forceFill(['is_active' => false])->save();
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();
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
