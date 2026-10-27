<?php

namespace Tests\Feature;

use App\Models\MemberLevelAssignment;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\Av\AvEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AvEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_track_level_grant_and_explicit_member_override(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $level = MembershipLevel::query()->firstOrCreate(['code' => 'av_test'], ['name' => 'AV Test']);
        MemberLevelAssignment::query()->create([
            'user_id' => $member->id,
            'membership_level_id' => $level->id,
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
        DB::table('av_track_level_access')->insert(['track_id' => $trackId, 'member_level_id' => $level->id]);

        $service = new AvEntitlement;
        $this->assertTrue($service->track($member, $trackId));

        DB::table('av_track_member_access')->insert([
            'track_id' => $trackId, 'user_id' => $member->id, 'allowed' => false,
        ]);
        $this->assertFalse($service->track($member, $trackId));

        DB::table('av_track_member_access')->where('track_id', $trackId)->update(['allowed' => true]);
        $this->assertTrue($service->track($member, $trackId));

        $member->forceFill(['is_active' => false]);
        $this->assertFalse($service->track($member, $trackId));
    }

    public function test_video_access_rejects_expired_and_revoked_levels(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $level = MembershipLevel::query()->firstOrCreate(['code' => 'av_video_test'], ['name' => 'AV Video Test']);
        $assignment = MemberLevelAssignment::query()->create([
            'user_id' => $member->id,
            'membership_level_id' => $level->id,
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
        DB::table('av_video_level_access')->insert(['video_id' => $videoId, 'member_level_id' => $level->id]);
        $service = new AvEntitlement;

        $this->assertTrue($service->video($member, $videoId));
        $assignment->update(['ends_at' => now()->subMinute()]);
        $this->assertFalse($service->video($member, $videoId));
        $assignment->update(['ends_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->assertFalse($service->video($member, $videoId));
    }
}
