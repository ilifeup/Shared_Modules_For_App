<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Av\AvEntitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvCatalogController extends Controller
{
    public function tracks(Request $request, AvEntitlement $entitlement): JsonResponse
    {
        $member = $request->user();
        $items = DB::table('av_music_tracks')
            ->where('is_published', true)
            ->orderBy('id')
            ->get(['id', 'title', 'category'])
            ->filter(fn ($track) => $entitlement->track($member, (int) $track->id))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function videos(Request $request, AvEntitlement $entitlement): JsonResponse
    {
        $member = $request->user();
        $items = DB::table('av_videos')
            ->where('is_published', true)
            ->orderBy('id')
            ->get(['id', 'title', 'youtube_id'])
            ->filter(fn ($video) => $entitlement->video($member, (int) $video->id))
            ->values();

        return response()->json(['data' => $items]);
    }
}
