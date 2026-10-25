<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CheckinLocation;
use App\Services\Calendar\GoogleCalendarSync;
use App\Support\Api\ApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S9 M14 platform association files (only when the real IDs are configured, D17),
 * the Location QR landing page and the Google OAuth callback page.
 */
class WellKnownController extends Controller
{
    /** Paths the App handles; nothing else is claimed. */
    public const APP_PATHS = ['/articles/*', '/events/*', '/notices/*', '/checkin/l/*'];

    public function assetLinks(): JsonResponse
    {
        $package = config('deeplinks.android.package');
        $fingerprints = (array) config('deeplinks.android.sha256_fingerprints');
        abort_if(blank($package) || $fingerprints === [], 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => $fingerprints],
        ]]);
    }

    public function appleAssociation(): JsonResponse
    {
        $appId = config('deeplinks.ios.app_id');
        abort_if(blank($appId), 404);

        return response()->json(['applinks' => ['apps' => [], 'details' => [['appID' => $appId, 'paths' => self::APP_PATHS]]]]);
    }

    public function checkinLocation(string $token): View
    {
        $location = CheckinLocation::query()->where('token', $token)->where('is_active', true)->first();
        abort_if($location === null, 404);

        return view('links.checkin-location', ['location' => $location, 'pageTitle' => '簽到地點']);
    }

    public function googleCallback(Request $request, GoogleCalendarSync $google): View
    {
        if ($request->filled('error') || ! $request->filled('code') || ! $request->filled('state')) {
            return view('links.google-connected', ['ok' => false, 'message' => '已取消或未完成 Google 授權，請回到 App 重新連結。', 'pageTitle' => 'Google 日曆']);
        }

        try {
            $google->completeAuthorization((string) $request->query('state'), (string) $request->query('code'));
        } catch (ApiException $e) {
            return view('links.google-connected', ['ok' => false, 'message' => $e->getMessage(), 'pageTitle' => 'Google 日曆']);
        } catch (\Throwable $e) {
            report($e);

            return view('links.google-connected', ['ok' => false, 'message' => '暫時無法連結 Google 日曆，請稍後再試。', 'pageTitle' => 'Google 日曆']);
        }

        return view('links.google-connected', ['ok' => true, 'pageTitle' => 'Google 日曆']);
    }
}
