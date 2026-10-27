<?php

namespace Tests\Feature;

use App\Models\MemberLevelAssignment;
use App\Models\User;
use App\Services\Av\AvEntitlement;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AvEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_level_grant_and_explicit_member_override(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $levelId = DB::table('membership_levels')->insertGetId(['code' => 'av_test', 'name' => 'AV Test', 'rank' => 90, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        MemberLevelAssignment::query()->create([
            'user_id' => $member->id,
            'membership_level_id' => $levelId,
            'starts_at' => now()->subDay(),
            'reason' => 'test',
        ]);
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'Test music',
            'category' => 'test',
            'encrypted_storage_path' => 'av/test.enc',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('av_track_level_access')->insert(['track_id' => $trackId, 'member_level_id' => $levelId]);

        $service = app(AvEntitlement::class);
        $this->assertTrue($service->track($member, $trackId));

        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => false,
        ]);
        $this->assertFalse($service->track($member, $trackId));

        DB::table('av_track_member_access')->where('track_id', $trackId)->update(['allowed' => true]);
        $this->assertTrue($service->track($member, $trackId));

        DB::table('av_music_tracks')->where('id', $trackId)->update(['is_published' => false]);
        $this->assertFalse($service->track($member, $trackId));
        DB::table('av_music_tracks')->where('id', $trackId)->update(['is_published' => true]);

        $member->forceFill(['email_verified_at' => null]);
        $this->assertFalse($service->track($member, $trackId));
        $member->forceFill(['email_verified_at' => now()]);

        $member->forceFill(['is_active' => false]);
        $this->assertFalse($service->track($member, $trackId));
    }

    public function test_video_access_rejects_expired_and_revoked_levels(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $levelId = DB::table('membership_levels')->insertGetId(['code' => 'av_video_test', 'name' => 'AV Video Test', 'rank' => 91, 'is_default' => false, 'created_at' => now(), 'updated_at' => now()]);
        $assignment = MemberLevelAssignment::query()->create([
            'user_id' => $member->id,
            'membership_level_id' => $levelId,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->addDay(),
            'reason' => 'test',
        ]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'avtest12345',
            'title' => 'Test video',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('av_video_level_access')->insert(['video_id' => $videoId, 'member_level_id' => $levelId]);
        $service = app(AvEntitlement::class);

        $this->assertTrue($service->video($member, $videoId));
        DB::table('av_videos')->where('id', $videoId)->update(['is_published' => false]);
        $this->assertFalse($service->video($member, $videoId));
        DB::table('av_videos')->where('id', $videoId)->update(['is_published' => true]);
        app(Settings::class)->put(['verification_policy' => Settings::POLICY_PHONE]);
        // PR #49 grants existing verified members persisted eligibility.
        $member->refresh();
        $this->assertNotNull($member->verification_grandfathered_at);
        $this->assertTrue($service->video($member, $videoId));
        // Email-only accounts created after the switch are not grandfathered.
        $newMember = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'phone_verified_at' => null,
        ]);
        MemberLevelAssignment::query()->create([
            'user_id' => $newMember->id,
            'membership_level_id' => $levelId,
            'starts_at' => now()->subDay(),
            'reason' => 'test',
        ]);
        if (Schema::hasColumn('users', 'verification_grandfathered_at')) {
            $this->assertNull($newMember->refresh()->verification_grandfathered_at);
        }
        $this->assertFalse($service->video($newMember, $videoId));

        $member->forceFill(['phone_verified_at' => now()]);
        $this->assertTrue($service->video($member, $videoId));
        $assignment->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse($service->video($member, $videoId));
        $assignment->forceFill(['ends_at' => now()->addDay(), 'revoked_at' => now()])->save();
        $this->assertFalse($service->video($member, $videoId));
    }

    public function test_video_entitlement_rejects_inactive_member_and_future_level(): void
    {
        $member = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $levelId = DB::table('membership_levels')->insertGetId([
            'code' => 'av_future_video', 'name' => 'Future Video',
            'rank' => 92, 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $assignment = MemberLevelAssignment::query()->create([
            'user_id' => $member->id,
            'membership_level_id' => $levelId,
            'starts_at' => now()->addDay(),
            'reason' => 'test',
        ]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'future_video_01',
            'title' => 'Future video',
            'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('av_video_level_access')->insert([
            'video_id' => $videoId, 'member_level_id' => $levelId,
        ]);

        $service = app(AvEntitlement::class);
        $this->assertFalse($service->video($member, $videoId));

        $assignment->update(['starts_at' => now()->subDay()]);
        $this->assertTrue($service->video($member, $videoId));

        $member->forceFill(['is_active' => false]);
        $this->assertFalse($service->video($member, $videoId));
    }

    public function test_track_override_cannot_bypass_member_eligibility_or_publication(): void
    {
        $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $trackId = DB::table('av_music_tracks')->insertGetId([
            'title' => 'Override only track',
            'category' => 'test',
            'encrypted_storage_path' => 'av/override-only.enc',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => true,
        ]);

        $service = app(AvEntitlement::class);
        $this->assertTrue($service->track($member, $trackId));

        DB::table('av_music_tracks')->where('id', $trackId)->update(['is_published' => false]);
        $this->assertFalse($service->track($member, $trackId));

        DB::table('av_music_tracks')->where('id', $trackId)->update(['is_published' => true]);
        $member->forceFill(['is_active' => false]);
        $this->assertFalse($service->track($member, $trackId));

        $member->forceFill(['is_active' => true, 'email_verified_at' => null]);
        $this->assertFalse($service->track($member, $trackId));
    }

    public function test_video_entitlement_never_grants_access_without_a_current_member_level(): void
    {
        $member = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $videoId = DB::table('av_videos')->insertGetId([
            'youtube_id' => 'no_level_01', 'title' => 'Restricted video', 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(app(AvEntitlement::class)->video($member, $videoId));
    }
}
