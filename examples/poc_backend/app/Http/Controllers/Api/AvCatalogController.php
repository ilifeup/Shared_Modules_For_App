<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentAccess\ContentAccessPolicy;
use App\Support\VerificationPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvCatalogController extends Controller
{
    public function tracks(Request $request, VerificationPolicy $verification, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'music'), 403);
        abort_unless($member->is_active && (int) $member->verified_status === 2, 403);
        $items = DB::table('av_music_tracks as tracks')
            ->where('tracks.is_published', true)
            ->where(function (Builder $query) use ($member): void {
                $query->whereExists(function (Builder $grant) use ($member): void {
                    $grant->selectRaw('1')->from('av_track_member_access as access')
                        ->whereColumn('access.track_id', 'tracks.id')
                        ->where('access.user_id', $member->id)->where('access.allowed', true);
                })->orWhere(function (Builder $levels) use ($member): void {
                    $levels->whereNotExists(function (Builder $override) use ($member): void {
                        $override->selectRaw('1')->from('av_track_member_access as access')
                            ->whereColumn('access.track_id', 'tracks.id')->where('access.user_id', $member->id);
                    })->whereExists(function (Builder $grant) use ($member): void {
                        $grant->selectRaw('1')->from('av_track_level_access as access')
                            ->join('member_level_assignments as assignments', 'assignments.membership_level_id', '=', 'access.member_level_id')
                            ->whereColumn('access.track_id', 'tracks.id')->where('assignments.user_id', $member->id)
                            ->whereNull('assignments.revoked_at')->where('assignments.starts_at', '<=', now())
                            ->where(function (Builder $dates): void {
                                $dates->whereNull('assignments.ends_at')->orWhere('assignments.ends_at', '>', now());
                            });
                    });
                });
            })
            ->orderBy('tracks.id')->get(['tracks.id', 'tracks.title', 'tracks.category']);

        return response()->json(['data' => $items]);
    }

    public function videos(Request $request, VerificationPolicy $verification, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'videos'), 403);
        abort_unless($member->is_active && (int) $member->verified_status === 2, 403);
        $items = DB::table('av_videos as videos')
            ->where('videos.is_published', true)
            ->whereExists(function (Builder $grant) use ($member): void {
                $grant->selectRaw('1')->from('av_video_level_access as access')
                    ->join('member_level_assignments as assignments', 'assignments.membership_level_id', '=', 'access.member_level_id')
                    ->whereColumn('access.video_id', 'videos.id')->where('assignments.user_id', $member->id)
                    ->whereNull('assignments.revoked_at')->where('assignments.starts_at', '<=', now())
                    ->where(function (Builder $dates): void {
                        $dates->whereNull('assignments.ends_at')->orWhere('assignments.ends_at', '>', now());
                    });
            })
            ->orderBy('videos.id')->get(['videos.id', 'videos.title', 'videos.youtube_id']);

        return response()->json(['data' => $items]);
    }
}
