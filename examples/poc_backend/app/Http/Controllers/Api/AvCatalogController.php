<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Av\AvEntitlement;
use App\Services\ContentAccess\ContentAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvCatalogController extends Controller
{
    public function tracks(Request $request, AvEntitlement $entitlement, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'music'), 403);
        $items = DB::table('av_music_tracks')
            ->where('is_published', true)
            ->orderBy('id')
            ->get(['id', 'title', 'category'])
            ->filter(fn ($track) => $entitlement->track($member, (int) $track->id))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function videos(Request $request, AvEntitlement $entitlement, ContentAccessPolicy $access): JsonResponse
    {
        $member = $request->user();
        abort_unless($access->allowsWhenConfigured($member, 'av', 'videos'), 403);
        $items = DB::table('av_videos')
            ->where('is_published', true)
            ->orderBy('id')
            ->get(['id', 'title', 'youtube_id'])
            ->filter(fn ($video) => $entitlement->video($member, (int) $video->id))
            ->values();

        return response()->json(['data' => $items]);
    }
}
