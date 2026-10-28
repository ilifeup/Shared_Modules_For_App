<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Services\Checkin\CheckinService;
use App\Services\Checkin\CheckinTokens;
use App\Support\Api\ApiException;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S9 M11 check-in API (online only, D13). Members: own attendees; staff
 * (checkin.staff): event QR display, attendee-pass scans, attendance list.
 */
class CheckinController extends Controller
{
    public function __construct(
        private readonly CheckinService $checkins,
        private readonly CheckinTokens $tokens,
        private readonly Settings $settings,
    ) {}

    /**
     * GET /api/v1/events/{id}/checkin — settings and my attendees with their status.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $event = CalendarEvent::query()->listed()->findOrFail($id);

        return new JsonResponse(['data' => [
            'settings' => $this->settings($event),
            'attendees' => $this->checkins->attendeesOf($event, $request->user())->map(fn (EventBookingAttendee $a) => $this->attendee($a))->values()->all(),
        ]]);
    }

    /**
     * POST /api/v1/events/{id}/checkin {attendee_id, event_token | location_token, lat?, lng?, accuracy?}
     */
    public function checkIn(Request $request, int $id): JsonResponse
    {
        $data = $this->validateScan($request);
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $results = $this->checkins->memberCheckIn($event, $request->user(), (int) $data['attendee_id'], $data['event_token'] ?? null, $data['location_token'] ?? null, [
            'lat' => $data['lat'] ?? null, 'lng' => $data['lng'] ?? null, 'accuracy' => $data['accuracy'] ?? null,
        ]);

        return new JsonResponse(['data' => array_map(fn (array $r) => $this->result($r), $results)]);
    }

    /**
     * POST /api/v1/events/{id}/checkout {attendee_id, event_token | location_token} — same QR entry (D23).
     */
    public function checkOut(Request $request, int $id): JsonResponse
    {
        $data = $this->validateScan($request, false);
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $this->assertScanToken($event, $data);

        // Member checkout is strictly one attendee per request.
        $attendeeId = (int) $data['attendee_id'];
        $attendee = $this->checkins->attendeesOf($event, $request->user())->firstWhere('id', $attendeeId);
        if ($attendee === null) {
            throw new ApiException(404, 'NOT_FOUND', '找不到這位參加者。');
        }

        $result = DB::transaction(function () use ($attendeeId, $attendee, $event, $request): array {
            EventBookingAttendee::query()->whereKey($attendeeId)->lockForUpdate()->firstOrFail();
            $this->checkins->assertMemberMayManageAttendee($request->user(), $attendee);

            return $this->result($this->checkins->checkOut($event, $attendee, $request->user()));
        });

        return new JsonResponse(['data' => [$result]]);
    }

    /**
     * GET /api/v1/events/{id}/checkin/pass — rotating attendee passes for staff to scan (D08).
     */
    public function pass(Request $request, int $id): JsonResponse
    {
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $attendees = $this->checkins->attendeesOf($event, $request->user())->where('status', EventBookingAttendee::STATUS_CONFIRMED);

        return new JsonResponse(['data' => [
            'expires_in' => $this->tokens->expiresIn(),
            'window_seconds' => CheckinTokens::WINDOW_SECONDS,
            'passes' => $attendees->map(fn (EventBookingAttendee $a) => [
                'attendee_id' => (string) $a->id,
                'name' => $a->name,
                'token' => $this->tokens->attendee($a->id),
            ])->values()->all(),
        ]]);
    }

    /**
     * GET /api/v1/checkin/locations/{token} — open occurrences of a Location QR (D19).
     */
    public function location(Request $request, string $token): JsonResponse
    {
        $location = $this->checkins->location($token);
        $events = $this->checkins->locationEvents($token);

        return new JsonResponse(['data' => [
            'location' => ['name' => $location->name, 'has_coordinates' => $location->latitude !== null && $location->longitude !== null],
            'events' => $events->map(fn (CalendarEvent $e) => [
                'id' => (string) $e->id,
                'title' => $e->title,
                'starts_at' => $e->starts_at->toIso8601ZuluString(),
                'ends_at' => $e->ends_at->toIso8601ZuluString(),
                'settings' => $this->settings($e),
                'attendees' => $this->checkins->attendeesOf($e, $request->user())->map(fn (EventBookingAttendee $a) => $this->attendee($a))->values()->all(),
            ])->values()->all(),
        ]]);
    }

    /**
     * GET /api/v1/events/{id}/checkin/qr — staff: the current 60-second event QR (D21, D25).
     */
    public function qr(Request $request, int $id): JsonResponse
    {
        $this->checkins->assertStaff($request->user());
        $event = CalendarEvent::query()->listed()->findOrFail($id);

        return new JsonResponse(['data' => [
            'event_id' => (string) $event->id,
            'token' => $this->tokens->event($event->id),
            'expires_in' => $this->tokens->expiresIn(),
            'window_seconds' => CheckinTokens::WINDOW_SECONDS,
            'grace_seconds' => CheckinTokens::GRACE_SECONDS,
        ]]);
    }

    /**
     * POST /api/v1/events/{id}/checkin/staff {attendee_token | attendee_id, action: checkin|checkout}
     */
    public function staff(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'attendee_token' => ['required_without:attendee_id', 'nullable', 'string', 'max:100'],
            'attendee_id' => ['required_without:attendee_token', 'nullable', 'integer', 'min:1'],
            'action' => ['sometimes', 'in:checkin,checkout'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $event = CalendarEvent::query()->listed()->findOrFail($id);

        if (($data['action'] ?? 'checkin') === 'checkout') {
            $this->checkins->assertStaff($request->user());
            $attendeeId = isset($data['attendee_token']) ? $this->tokens->verify($data['attendee_token'], 'a') : (int) $data['attendee_id'];
            $attendee = $attendeeId !== null ? EventBookingAttendee::query()->where('calendar_event_id', $event->id)->find($attendeeId) : null;
            if ($attendee === null) {
                throw new ApiException(422, 'QR_EXPIRED', '參加者 QR Code 已過期或不屬於此場次。');
            }

            return new JsonResponse(['data' => $this->result($this->checkins->checkOut($event, $attendee, $request->user()))]);
        }

        return new JsonResponse(['data' => $this->result($this->checkins->staffCheckIn(
            $event, $request->user(), $data['attendee_token'] ?? null, isset($data['attendee_id']) ? (int) $data['attendee_id'] : null, $data['contact'] ?? null,
        ))]);
    }

    /**
     * GET /api/v1/events/{id}/attendance — staff / admins only (D18, D29); contacts masked.
     */
    public function attendance(Request $request, int $id): JsonResponse
    {
        $this->checkins->assertStaff($request->user());
        $event = CalendarEvent::query()->listed()->findOrFail($id);
        $attendees = EventBookingAttendee::query()->with('group', 'attendance')->where('calendar_event_id', $event->id)
            ->where('status', EventBookingAttendee::STATUS_CONFIRMED)->orderBy('name')->orderBy('id')->get();

        return new JsonResponse(['data' => [
            'summary' => $this->checkins->summary($event),
            'attendees' => $attendees->map(fn (EventBookingAttendee $a) => $this->attendee($a) + [
                'contact_name' => $a->group->contact_name,
                'contact_email' => $a->group->contact_email !== null ? self::maskEmail($a->group->contact_email) : null,
                'contact_phone' => $a->group->contact_phone !== null ? Phone::mask($a->group->contact_phone) : null,
            ])->values()->all(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateScan(Request $request, bool $validateGps = true): array
    {
        $rules = [
            'attendee_id' => ['required', 'integer', 'min:1'],
            'attendee_ids' => ['prohibited'],
            'event_token' => ['required_without:location_token', 'nullable', 'string', 'max:100'],
            'location_token' => ['required_without:event_token', 'nullable', 'string', 'max:64'],
        ];
        if ($validateGps) {
            $rules += [
                'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
                'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
                'accuracy' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            ];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertScanToken(CalendarEvent $event, array $data): void
    {
        if (isset($data['event_token'])) {
            if ($this->tokens->verify($data['event_token'], 'e') !== $event->id) {
                throw new ApiException(422, 'QR_EXPIRED', 'QR Code 已過期，請重新掃描現場最新的 QR Code。');
            }

            return;
        }
        $location = $this->checkins->location((string) $data['location_token']);
        if (! $location->events()->whereKey($event->id)->exists()) {
            throw new ApiException(422, 'LOCATION_EVENT_MISMATCH', '此地點 QR Code 不適用於這個活動場次。');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(CalendarEvent $event): array
    {
        return [
            'enabled' => (bool) $event->checkin_enabled,
            'checkout_enabled' => (bool) $event->checkout_enabled,
            'opens_at' => $event->checkinOpensAt()->toIso8601ZuluString(),
            'closes_at' => $event->checkinClosesAt()->toIso8601ZuluString(),
            'open_now' => (bool) $event->checkin_enabled && now()->between($event->checkinOpensAt(), $event->checkinClosesAt()),
            'gps_required' => $event->checkin_gps_required ?? $this->settings->bool('checkin_gps_required'),
            'gps_radius_m' => $event->checkin_gps_radius_m ?? $this->settings->int('checkin_gps_radius_m'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendee(EventBookingAttendee $a): array
    {
        $att = $a->attendance;

        return [
            'id' => (string) $a->id,
            'name' => $a->name,
            'booking_status' => $a->status,
            'checkin_status' => $att?->status,
            'checked_in_at' => $att?->checked_in_at?->toIso8601ZuluString(),
            'checked_out_at' => $att?->checked_out_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array{attendee_id: string, result: string, attendance: EventAttendance}  $r
     * @return array<string, mixed>
     */
    private function result(array $r): array
    {
        return [
            'attendee_id' => $r['attendee_id'],
            'result' => $r['result'],
            'checkin_status' => $r['attendance']->status,
            'checked_in_at' => $r['attendance']->checked_in_at?->toIso8601ZuluString(),
            'checked_out_at' => $r['attendance']->checked_out_at?->toIso8601ZuluString(),
        ];
    }

    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}
