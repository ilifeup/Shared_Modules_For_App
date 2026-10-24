<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Support\Api\ApiException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * S5 M09: events overlapping a date range. Drafts are never returned; cancelled
 * events are returned with status "cancelled". Member endpoints (behind the
 * verification policy) include member-only events; public endpoints only public ones.
 */
class CalendarEventController extends Controller
{
    /**
     * GET /api/v1/events?from=&to=
     */
    public function index(Request $request): JsonResponse
    {
        return $this->range($request, CalendarEvent::query()->listed());
    }

    /**
     * GET /api/v1/events/{id}
     */
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => new CalendarEventResource(CalendarEvent::query()->listed()->findOrFail($id))]);
    }

    /**
     * GET /api/v1/public/events?from=&to=
     */
    public function publicIndex(Request $request): JsonResponse
    {
        return $this->range($request, CalendarEvent::query()->visibleToPublic());
    }

    /**
     * GET /api/v1/public/events/{id}
     */
    public function publicShow(int $id): JsonResponse
    {
        return new JsonResponse(['data' => new CalendarEventResource(CalendarEvent::query()->visibleToPublic()->findOrFail($id))]);
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     */
    private function range(Request $request, Builder $query): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'string', 'max:40'],
            'to' => ['required', 'string', 'max:40'],
        ]);

        $from = $this->instant($request->string('from')->toString(), 'from');
        $to = $this->instant($request->string('to')->toString(), 'to');

        if ($to->lte($from)) {
            throw $this->invalid('to', '結束時間必須晚於開始時間。');
        }

        if ($from->diffInDays($to, true) > CalendarEvent::MAX_RANGE_DAYS) {
            throw $this->invalid('to', '查詢區間最多 '.CalendarEvent::MAX_RANGE_DAYS.' 天。');
        }

        $events = $query->overlapping($from, $to)
            ->orderBy('starts_at')->orderBy('id')
            ->limit(CalendarEvent::MAX_RESULTS + 1)
            ->get();

        $truncated = $events->count() > CalendarEvent::MAX_RESULTS;

        return new JsonResponse([
            'data' => CalendarEventResource::collection($events->take(CalendarEvent::MAX_RESULTS))->resolve(),
            'meta' => [
                'from' => $from->toIso8601ZuluString(),
                'to' => $to->toIso8601ZuluString(),
                'count' => min($events->count(), CalendarEvent::MAX_RESULTS),
                'truncated' => $truncated,
            ],
        ]);
    }

    /**
     * ISO 8601 date-time with offset (e.g. 2026-11-01T00:00:00+08:00) or a date
     * (YYYY-MM-DD, taken as 00:00 UTC). Returned in UTC.
     */
    private function instant(string $value, string $field): Carbon
    {
        $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;

        try {
            if ($ok) {
                return Carbon::parse($value, 'UTC')->utc();
            }
        } catch (Throwable) {
            // Falls through to the validation error.
        }

        throw $this->invalid($field, '請使用 ISO 8601 時間，例如 2026-11-01T00:00:00+08:00。');
    }

    private function invalid(string $field, string $message): ApiException
    {
        return new ApiException(422, 'VALIDATION_FAILED', $message, [$field => [$message]]);
    }
}
