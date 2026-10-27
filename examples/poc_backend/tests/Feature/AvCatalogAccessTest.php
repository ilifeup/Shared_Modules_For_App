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

    public function test_catalog_sql_preserves_explicit_music_deny_and_active_level_grants(): void
    {
        $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
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

    public function test_phone_policy_transition_preserves_only_grandfathered_member_av_catalog_access(): void
    {
        $settings = app(Settings::class);
        $settings->put(['verification_policy' => Settings::POLICY_EMAIL]);
        $existing = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => null,
        ]);
        $levelId = DB::table('membership_levels')->insertGetId([
            'code' => 'av_policy_http', 'name' => 'AV Policy HTTP', 'rank' => 96,
            'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('member_level_assignments')->insert([
            'user_id' => $existing->id, 'membership_level_id' => $levelId,
            'starts_at' => now()->subDay(), 'ends_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'Grandfathered track', 'category' => 'test',
            'encrypted_storage_path' => 'av/grandfathered.enc',
            'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'grandfather_http_01', 'title' => 'Grandfathered video',
            'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('av_track_level_access')->insert(['track_id' => $trackId, 'member_level_id' => $levelId]);
        DB::table('av_video_level_access')->insert(['video_id' => $videoId, 'member_level_id' => $levelId]);

        Sanctum::actingAs($existing);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonPath('data.0.id', $trackId);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonPath('data.0.id', $videoId);

        // The same authenticated member keeps access after the administrator
        // switches the policy; no phone verification is fabricated.
        $settings->put(['verification_policy' => Settings::POLICY_PHONE]);
        $existing->refresh();
        $this->assertNotNull($existing->verification_grandfathered_at);
        $this->assertNull($existing->phone_verified_at);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonPath('data.0.id', $trackId);
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonPath('data.0.id', $videoId);

        // A newly registered email-only account with the same level is denied
        // at the HTTP verification boundary, not merely in the service.
        $newMember = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
            'phone_verified_at' => null,
        ]);
        DB::table('member_level_assignments')->insert([
            'user_id' => $newMember->id, 'membership_level_id' => $levelId,
            'starts_at' => now()->subDay(), 'ends_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNull($newMember->refresh()->verification_grandfathered_at);
        Sanctum::actingAs($newMember);
        $this->getJson('/api/v1/av/tracks')->assertForbidden();
        $this->getJson('/api/v1/av/videos')->assertForbidden();

        // Existing qualification does not override a revoked AV level.
        Sanctum::actingAs($existing);
        DB::table('member_level_assignments')->where('user_id', $existing->id)
            ->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/av/tracks')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/av/videos')->assertOk()->assertJsonCount(0, 'data');
    }
}
