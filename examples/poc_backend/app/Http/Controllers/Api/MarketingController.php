<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Services\ArticleAccess;
use App\Services\Marketing\MarketingService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S9 M15 display API: campaigns of a placement for the current viewer (optional login).
 */
class MarketingController extends Controller
{
    public function __construct(
        private readonly MarketingService $marketing,
        private readonly ArticleAccess $access,
    ) {}

    /**
     * GET /api/v1/marketing/placements/{code}
     */
    public function placement(Request $request, string $code): JsonResponse
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $code) !== 1) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此展示區塊。');
        }

        return new JsonResponse(['data' => $this->marketing->forPlacement($code, $this->access->viewer($request))]);
    }

    /**
     * GET /api/v1/marketing/campaigns/{id}/image — only while the campaign is displayed.
     */
    public function image(int $id): StreamedResponse
    {
        $campaign = MarketingCampaign::query()->findOrFail($id);
        if (! $this->marketing->isDisplayable($campaign) || $campaign->image_path === null || ! Storage::disk('local')->exists($campaign->image_path)) {
            throw new ApiException(404, 'NOT_FOUND', '找不到圖片。');
        }

        return Storage::disk('local')->response($campaign->image_path, null, ['Cache-Control' => 'public, max-age=300']);
    }
}
