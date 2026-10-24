<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            report($e);

            return ApiError::response($request, 503, 'DATABASE_UNAVAILABLE', '資料庫暫時無法連線。');
        }

        return new JsonResponse([
            'data' => [
                'status' => 'ok',
                'database' => 'ok',
                'server_time' => now()->utc()->toIso8601ZuluString(),
            ],
        ]);
    }
}
