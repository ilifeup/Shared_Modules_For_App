<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContentAccess\ContentAccessPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvCatalogController extends Controller
{
    public function tracks(Request $request, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'music'), 403);
        abort_unless($member->is_active && $member->isVerifiedMember(), 403);
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

    /** Authorization-only handoff; no protected file path or permanent URL. */
    public function trackAccess(Request $request, ContentAccessPolicy $access, \App\Services\Av\AvEntitlement $entitlement, int $id): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'music'), 403);
        abort_unless($entitlement->track($member, $id), 403);

        return response()->json(['data' => ['id' => $id, 'allowed' => true]]);
    }

    /** Recheck video entitlement when a member opens the item. */
    public function videoAccess(Request $request, ContentAccessPolicy $access, \App\Services\Av\AvEntitlement $entitlement, int $id): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'videos'), 403);
        abort_unless($entitlement->video($member, $id), 403);

        return response()->json(['data' => ['id' => $id, 'allowed' => true]]);
    }

    /** Encrypted private track bytes, checked against current member grants. */
    public function trackContent(Request $request, ContentAccessPolicy $access, \App\Services\Av\AvEntitlement $entitlement, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'music'), 403);
        abort_unless($entitlement->track($member, $id), 403);
        $path = DB::table('av_music_tracks')->where('id', $id)->value('encrypted_storage_path');
        abort_unless(is_string($path) && str_starts_with($path, 'av/') && ! str_contains($path, '..'), 404);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment',
        ]);
    }

    public function videos(Request $request, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'videos'), 403);
        abort_unless($member->is_active && $member->isVerifiedMember(), 403);
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
