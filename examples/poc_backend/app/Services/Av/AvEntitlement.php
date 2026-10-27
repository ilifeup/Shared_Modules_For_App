<?php

namespace App\Services\Av;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared entitlement decision: use only active member-level assignments.
 * Per-member override is authoritative (including explicit deny).
 * Caller must enforce auth:sanctum and member.verified before invoking.
 */
final class AvEntitlement
{
    private function eligible(User $member): bool
    {
        return (bool) $member->is_active && $member->isVerifiedMember();
    }

    public function track(User $member, int $trackId): bool
    {
        if (! $this->eligible($member) || ! DB::table('av_music_tracks')->where('id', $trackId)->where('is_published', true)->exists()) {
            return false;
        }

        $override = DB::table('av_track_member_access')
            ->where('track_id', $trackId)
            ->where('user_id', $member->id)
            ->first(['allowed']);

        if ($override !== null) {
            return (bool) $override->allowed;
        }

        return $this->hasLevelGrant($member, 'av_track_level_access', 'track_id', $trackId);
    }

    public function video(User $member, int $videoId): bool
    {
        return $this->eligible($member)
            && DB::table('av_videos')->where('id', $videoId)->where('is_published', true)->exists()
            && $this->hasLevelGrant($member, 'av_video_level_access', 'video_id', $videoId);
    }

    private function hasLevelGrant(User $member, string $table, string $contentColumn, int $contentId): bool
    {
        $now = now();

        return DB::table($table)
            ->join('member_level_assignments', 'member_level_assignments.membership_level_id', '=', $table.'.member_level_id')
            ->where($table.'.'.$contentColumn, $contentId)
            ->where('member_level_assignments.user_id', $member->id)
            ->whereNull('member_level_assignments.revoked_at')
            ->where('member_level_assignments.starts_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('member_level_assignments.ends_at')
                    ->orWhere('member_level_assignments.ends_at', '>', $now);
            })
            ->exists();
    }
}
