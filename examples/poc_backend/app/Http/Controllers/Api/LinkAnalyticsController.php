<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\LinkAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S9 M17 public collection endpoints (rate limited). Answers never depend on
 * whether recording worked, so analytics can never block a link (S9-INT-D11).
 */
class LinkAnalyticsController extends Controller
{
    public function __construct(private readonly LinkAnalytics $analytics) {}

    /**
     * POST /api/v1/links/clicks {content_type, content_id, utm_*?} — App taps (e.g. a campaign).
     */
    public function click(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $clickId = $this->analytics->click($data['content_type'], (int) $data['content_id'], $request, 'app');

        return new JsonResponse(['data' => ['click_id' => $clickId]], 202);
    }

    /**
     * POST /api/v1/links/opens {click_id} (web page) or {content_type, content_id, utm_*?} (App opened
     * the content). Only explicit reports count as a successful open (D21, D25).
     */
    public function open(Request $request): JsonResponse
    {
        if ($request->filled('click_id')) {
            $request->validate(['click_id' => ['required', 'uuid']]);
            $counted = $this->analytics->confirmOpen((string) $request->input('click_id'));

            return new JsonResponse(['data' => ['counted' => $counted]], 202);
        }

        $data = $this->validated($request);
        $clickId = $this->analytics->appOpen($data['content_type'], (int) $data['content_id'], $request);

        return new JsonResponse(['data' => ['counted' => $clickId !== null, 'click_id' => $clickId]], 202);
    }

    /**
     * @return array{content_type: string, content_id: int}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'content_type' => ['required', 'in:'.implode(',', LinkAnalytics::TYPES)],
            'content_id' => ['required', 'integer', 'min:1'],
            'utm_source' => ['sometimes', 'nullable', 'string', 'max:40'],
            'utm_medium' => ['sometimes', 'nullable', 'string', 'max:40'],
            'utm_campaign' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
    }
}
