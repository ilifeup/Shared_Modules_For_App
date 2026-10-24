<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\PrivateCalendarEvent;
use App\Services\Calendar\CalendarVip;
use App\Services\Calendar\GoogleCalendarSync;
use App\Services\Calendar\PrivateCalendarService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * S9 M09 member calendar: public and member events with my booking state, VIP
 * private entries, event categories and the Google Calendar connection.
 */
class MyCalendarController extends Controller
{
    public function __construct(
        private readonly PrivateCalendarService $private,
        private readonly CalendarVip $vip,
        private readonly GoogleCalendarSync $google,
    ) {}

    /**
     * GET /api/v1/event-categories — name and colour (M09-D10).
     */
    public function categories(): JsonResponse
    {
        return new JsonResponse(['data' => EventCategory::query()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (EventCategory $c) => ['id' => (string) $c->id, 'name' => $c->name, 'color' => $c->color])->all()]);
    }

    /**
     * GET /api/v1/me/calendar?from=&to= (max 62 days) — month / week / list views (D01, D02).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date']]);
        try {
            $from = Carbon::parse($request->string('from')->toString(), 'UTC')->utc();
            $to = Carbon::parse($request->string('to')->toString(), 'UTC')->utc();
        } catch (Throwable) {
            throw new ApiException(422, 'VALIDATION_FAILED', '時間格式錯誤。', ['from' => ['時間格式錯誤。']]);
        }
        if ($to->lte($from) || $from->diffInDays($to, true) > CalendarEvent::MAX_RANGE_DAYS) {
            throw new ApiException(422, 'VALIDATION_FAILED', '查詢區間最多 '.CalendarEvent::MAX_RANGE_DAYS.' 天。', ['to' => ['查詢區間最多 '.CalendarEvent::MAX_RANGE_DAYS.' 天。']]);
        }

        $member = $request->user();
        $events = CalendarEvent::query()->listed()->with('category')->overlapping($from, $to)
            ->orderBy('starts_at')->orderBy('id')->limit(CalendarEvent::MAX_RESULTS + 1)->get();
        $ids = $events->pluck('id');

        $mine = EventBookingAttendee::query()->whereIn('calendar_event_id', $ids)->where('member_user_id', $member->id)->where('active', 1)
            ->pluck('status', 'calendar_event_id')->all();
        $booked = EventBookingGroup::query()->whereIn('calendar_event_id', $ids)->where('booker_user_id', $member->id)
            ->whereIn('status', [EventBookingGroup::STATUS_CONFIRMED, EventBookingGroup::STATUS_WAITLISTED])
            ->pluck('status', 'calendar_event_id')->all();

        $vip = $this->vip->isVip($member);

        return new JsonResponse([
            'data' => [
                'events' => $events->take(CalendarEvent::MAX_RESULTS)->map(fn (CalendarEvent $e) => (new CalendarEventResource($e))->resolve($request) + [
                    'my_booking_status' => $booked[$e->id] ?? $mine[$e->id] ?? null,
                ])->values()->all(),
                'private_events' => array_map(fn (array $o) => [
                    'id' => (string) $o['entry']->id,
                    'title' => $o['entry']->title,
                    'notes' => $o['entry']->notes,
                    'starts_at' => $o['starts_at']->toIso8601ZuluString(),
                    'ends_at' => $o['ends_at']->toIso8601ZuluString(),
                    'timezone' => $o['entry']->timezone,
                    'recurrence' => $o['entry']->recurrence,
                    'recurrence_until' => $o['entry']->recurrence_until?->toDateString(),
                    'editable' => $vip,
                ], $this->private->expand($member, $from, $to)),
                'vip' => $vip,
            ],
            'meta' => ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString(), 'truncated' => $events->count() > CalendarEvent::MAX_RESULTS],
        ]);
    }

    /**
     * POST /api/v1/me/private-events
     */
    public function store(Request $request): JsonResponse
    {
        $entry = $this->private->create($request->user(), $this->input($request, true));

        return new JsonResponse(['data' => $this->entry($entry, true)], 201);
    }

    /**
     * PATCH /api/v1/me/private-events/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $entry = PrivateCalendarEvent::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $entry = $this->private->update($request->user(), $entry, $this->input($request, false));

        return new JsonResponse(['data' => $this->entry($entry, true)]);
    }

    /**
     * DELETE /api/v1/me/private-events/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = PrivateCalendarEvent::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->private->delete($request->user(), $entry);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /**
     * GET /api/v1/me/google-calendar
     */
    public function googleStatus(Request $request): JsonResponse
    {
        $c = $this->google->connectionOf($request->user());
        $failed = $c?->mappings()->where('status', 'failed')->count() ?? 0;

        return new JsonResponse(['data' => [
            'configured' => $this->google->configured(),
            'connected' => $c !== null && $c->isActive(),
            'status' => $c?->status ?? 'none',
            'last_synced_at' => $c?->last_synced_at?->toIso8601ZuluString(),
            'failed_items' => $failed,
            'last_error' => $c?->status === 'error' ? $c->last_error : null,
        ]]);
    }

    /**
     * POST /api/v1/me/google-calendar/connect — returns the Google consent URL.
     */
    public function googleConnect(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => ['authorization_url' => $this->google->authorizationUrl($request->user())]]);
    }

    /**
     * DELETE /api/v1/me/google-calendar — stop syncing; Google keeps existing events (D13).
     */
    public function googleDisconnect(Request $request): JsonResponse
    {
        $this->google->disconnect($request->user());

        return new JsonResponse(['data' => ['connected' => false]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request, bool $create): array
    {
        $rule = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$rule, 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'starts_at' => [$rule, 'date'],
            'ends_at' => [$rule, 'date'],
            'timezone' => ['sometimes', 'timezone:all'],
            'recurrence' => ['sometimes', 'nullable', 'in:weekly,monthly'],
            'recurrence_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        if ($create) {
            $data['timezone'] ??= 'Asia/Taipei';
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(PrivateCalendarEvent $entry, bool $editable): array
    {
        return [
            'id' => (string) $entry->id,
            'title' => $entry->title,
            'notes' => $entry->notes,
            'starts_at' => $entry->starts_at->toIso8601ZuluString(),
            'ends_at' => $entry->ends_at->toIso8601ZuluString(),
            'timezone' => $entry->timezone,
            'recurrence' => $entry->recurrence,
            'recurrence_until' => $entry->recurrence_until?->toDateString(),
            'editable' => $editable,
        ];
    }
}
