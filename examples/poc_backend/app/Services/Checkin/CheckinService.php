<?php

namespace App\Services\Checkin;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CheckinLocation;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\GuestClaimService;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S9 M11 one check-in service for all entries (D01): member scans the event QR,
 * staff scan the attendee pass, Location QR + optional GPS, admin corrections.
 * One attendance per attendee and occurrence (UNIQUE + row lock, D14); online
 * only (D13); waitlisted attendees cannot check in (D30).
 */
class CheckinService
{
    public const SOURCE_EVENT_QR = 'event_qr';

    public const SOURCE_STAFF_SCAN = 'staff_scan';

    public const SOURCE_STAFF_MANUAL = 'staff_manual';

    public const SOURCE_LOCATION_QR = 'location_qr';

    public const SOURCE_ADMIN = 'admin';

    public function __construct(
        private readonly Settings $settings,
        private readonly CheckinTokens $tokens,
    ) {}

    /**
     * Attendees the member may check in: their own place and the attendees of the
     * groups they booked (each attendee checks in separately, D03).
     *
     * @return Collection<int, EventBookingAttendee>
     */
    public function attendeesOf(CalendarEvent $event, User $member): Collection
    {
        return EventBookingAttendee::query()->with('attendance', 'group')
            ->where('calendar_event_id', $event->id)
            ->where(fn ($q) => $q->where('member_user_id', $member->id)
                ->orWhereIn('event_booking_group_id', EventBookingGroup::query()->select('id')->where('calendar_event_id', $event->id)->where('booker_user_id', $member->id)))
            ->whereIn('status', [EventBookingAttendee::STATUS_CONFIRMED, EventBookingAttendee::STATUS_WAITLISTED])
            ->orderBy('id')->get();
    }

    /**
     * Member self check-in with the event QR (D07) or a Location QR (D09, D19).
     *
     * @param  list<int>  $attendeeIds
     * @param  array{lat?: float|null, lng?: float|null, accuracy?: float|null}  $gps
     * @return list<array{attendee_id: string, result: string, attendance: EventAttendance}>
     */
    public function memberCheckIn(CalendarEvent $event, User $member, array $attendeeIds, ?string $eventToken, ?string $locationToken, array $gps = []): array
    {
        if ($eventToken !== null) {
            if ($this->tokens->verify($eventToken, 'e') !== $event->id) {
                throw new ApiException(422, 'QR_EXPIRED', 'QR Code 已過期，請重新掃描現場最新的 QR Code。');
            }
            $source = self::SOURCE_EVENT_QR;
        } elseif ($locationToken !== null) {
            $location = $this->location($locationToken);
            if (! $location->events()->whereKey($event->id)->exists()) {
                throw new ApiException(422, 'LOCATION_EVENT_MISMATCH', '此地點 QR Code 不適用於這個活動場次。');
            }
            $this->assertGps($event, $location, $gps);
            $source = self::SOURCE_LOCATION_QR;
        } else {
            throw new ApiException(422, 'VALIDATION_FAILED', '請掃描活動或地點 QR Code。');
        }

        $mine = $this->attendeesOf($event, $member)->keyBy('id');
        $ids = array_values(array_unique($attendeeIds));
        // Validate the entire requested ownership set before changing any attendance.
        foreach ($ids as $id) {
            if (! $mine->has($id)) {
                throw new ApiException(404, 'NOT_FOUND', '找不到這位參加者。');
            }
        }

        // All attendee state changes and their audit entries share one transaction.
        // Any failure rolls back the entire HTTP batch (M11-D07, #36 decision A).
        return DB::transaction(function () use ($ids, $mine, $event, $member, $source): array {
            // Lock existing parent rows in one stable order before inserting missing
            // attendance rows. Reverse-order concurrent batches otherwise deadlock
            // on MySQL gap/unique-key locks and return an unexpected HTTP 500.
            EventBookingAttendee::query()->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get(['id']);
            $results = [];
            foreach ($ids as $id) {
                $results[] = $this->checkIn($event, $mine->get($id), $member, $source);
            }

            return $results;
        });
    }

    /**
     * Staff scan of the attendee pass (D08) or staff manual check-in after checking
     * the booking contact and attendee (D11, D17). Time window still applies.
     *
     * @return array{attendee_id: string, result: string, attendance: EventAttendance}
     */
    public function staffCheckIn(CalendarEvent $event, User $staff, ?string $attendeeToken, ?int $attendeeId, ?string $contact = null): array
    {
        $this->assertStaff($staff);

        if ($attendeeToken !== null) {
            $attendeeId = $this->tokens->verify($attendeeToken, 'a');
            if ($attendeeId === null) {
                throw new ApiException(422, 'QR_EXPIRED', '參加者 QR Code 已過期，請對方重新整理。');
            }
            $source = self::SOURCE_STAFF_SCAN;
        } else {
            $source = self::SOURCE_STAFF_MANUAL;
        }

        $attendee = EventBookingAttendee::query()->with('group')->where('calendar_event_id', $event->id)->find($attendeeId);
        if ($attendee === null) {
            throw new ApiException(404, 'NOT_FOUND', '此參加者不屬於這個場次。');
        }

        // D11: a guest checked in by hand (no pass to scan) must give the Email or
        // phone used for the booking; staff never see the full contact (D29).
        if ($source === self::SOURCE_STAFF_MANUAL && $attendee->group->is_guest && $attendee->group->booker_user_id === null) {
            self::assertGuestContact($attendee->group, $contact);
        }

        return $this->checkIn($event, $attendee, $staff, $source);
    }

    /**
     * @return array{attendee_id: string, result: string, attendance: EventAttendance}
     */
    public function checkOut(CalendarEvent $event, EventBookingAttendee $attendee, User $actor): array
    {
        if (! $event->checkout_enabled) {
            throw new ApiException(409, 'CHECKOUT_DISABLED', '此活動未啟用簽退。');
        }

        // State change and its audit entry commit together or not at all.
        [$attendance, $result] = DB::transaction(function () use ($event, $attendee, $actor): array {
            $row = EventAttendance::query()->where('calendar_event_id', $event->id)->where('event_booking_attendee_id', $attendee->id)->lockForUpdate()->first();
            if ($row === null || $row->status === EventAttendance::REVOKED) {
                throw new ApiException(409, 'NOT_CHECKED_IN', '尚未簽到，無法簽退。');
            }
            if ($row->status === EventAttendance::CHECKED_OUT) {
                return [$row, 'already_checked_out'];
            }
            $row->forceFill(['status' => EventAttendance::CHECKED_OUT, 'checked_out_at' => now()])->save();
            $this->audit('checkin.checked_out', $actor, $row, ['event_id' => $event->id]);

            return [$row, 'checked_out'];
        });

        return ['attendee_id' => (string) $attendee->id, 'result' => $result, 'attendance' => $attendance];
    }

    /**
     * Admin check-in / revoke / correction (D12, D16, D27). A late check-in outside
     * the window and every revoke or correction need a reason; all are audited with
     * the state before and after.
     */
    public function adminSet(CalendarEvent $event, EventBookingAttendee $attendee, string $status, User $admin, ?string $reason): EventAttendance
    {
        if (! $admin->can('checkin.manage')) {
            throw new ApiException(403, 'FORBIDDEN', '沒有簽到管理權限。');
        }
        if (! in_array($status, [EventAttendance::CHECKED_IN, EventAttendance::CHECKED_OUT, EventAttendance::REVOKED], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', '不支援的簽到狀態。');
        }
        if ($attendee->calendar_event_id !== $event->id) {
            throw new ApiException(404, 'NOT_FOUND', '此參加者不屬於這個場次。');
        }
        if ($attendee->status !== EventBookingAttendee::STATUS_CONFIRMED && $status !== EventAttendance::REVOKED) {
            throw new ApiException(409, 'NOT_CONFIRMED', '候補或已取消的報名不可簽到（須先成為正式報名）。');
        }

        $outsideWindow = ! now()->between($event->checkinOpensAt(), $event->checkinClosesAt());

        // One transaction for the state change, the correction reason and the audit
        // entry: if any step fails nothing is kept (no changed state without its
        // reason or audit). The current row is read under the lock, so "before" and
        // the reason requirement cannot race with a parallel scan or correction.
        return DB::transaction(function () use ($event, $attendee, $status, $admin, $reason, $outsideWindow): EventAttendance {
            $existing = EventAttendance::query()->where('calendar_event_id', $event->id)->where('event_booking_attendee_id', $attendee->id)->lockForUpdate()->first();
            if (($outsideWindow || $existing !== null) && trim((string) $reason) === '') {
                throw new ApiException(422, 'REASON_REQUIRED', '逾時補登、撤銷或更正必須填寫原因。', ['reason' => ['請填寫原因。']]);
            }
            $before = $existing?->status;

            $row = $existing ?? new EventAttendance(['calendar_event_id' => $event->id, 'event_booking_attendee_id' => $attendee->id]);
            $row->forceFill([
                'status' => $status,
                'checked_in_at' => $status === EventAttendance::REVOKED ? $row->checked_in_at : ($row->checked_in_at ?? now()),
                'checked_out_at' => $status === EventAttendance::CHECKED_OUT ? ($row->checked_out_at ?? now()) : ($status === EventAttendance::CHECKED_IN ? null : $row->checked_out_at),
                'source' => self::SOURCE_ADMIN,
                'checked_in_by' => $admin->id,
            ])->save();

            // The reason is personal check-in data: it lives with the attendance and is
            // deleted at retention; the audit entry only records the de-identified facts.
            $correctionId = DB::table('event_attendance_corrections')->insertGetId([
                'event_attendance_id' => $row->id,
                'admin_id' => $admin->id,
                'from_status' => $before,
                'to_status' => $row->status,
                'late' => $outsideWindow,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 255) : null,
                'created_at' => now(),
            ]);
            AuditEvent::record('checkin.admin_'.$status, $admin->id, $row, [
                'event_id' => $event->id,
                'before' => $before === null ? null : ['status' => $before],
                'after' => ['status' => $row->status],
                'late' => $outsideWindow,
                'reason_recorded' => trim((string) $reason) !== '',
                'correction_id' => $correctionId,
            ]);

            return $row;
        });
    }

    /**
     * Occurrences of a Location QR that are open for check-in now (D19).
     *
     * @return Collection<int, CalendarEvent>
     */
    public function locationEvents(string $token): Collection
    {
        $location = $this->location($token);
        $now = now();

        return $location->events()->where('status', CalendarEvent::STATUS_PUBLISHED)->where('checkin_enabled', true)
            ->orderBy('starts_at')->get()
            ->filter(fn (CalendarEvent $e): bool => $now->between($e->checkinOpensAt(), $e->checkinClosesAt()))->values();
    }

    public function location(string $token): CheckinLocation
    {
        $location = CheckinLocation::query()->where('token', $token)->where('is_active', true)->first();
        if ($location === null) {
            throw new ApiException(404, 'NOT_FOUND', '此地點 QR Code 無效。');
        }

        return $location;
    }

    /**
     * Counts and the detail list (staff and admins only, D18, D29).
     *
     * @return array{booked: int, checked_in: int, absent: int, checked_out: int, checkout_enabled: bool}
     */
    public function summary(CalendarEvent $event): array
    {
        $booked = EventBookingAttendee::query()->where('calendar_event_id', $event->id)->where('status', EventBookingAttendee::STATUS_CONFIRMED)->count();
        $present = EventAttendance::query()->where('calendar_event_id', $event->id)->whereIn('status', [EventAttendance::CHECKED_IN, EventAttendance::CHECKED_OUT])
            ->whereHas('attendee', fn ($q) => $q->where('status', EventBookingAttendee::STATUS_CONFIRMED))->count();
        $out = EventAttendance::query()->where('calendar_event_id', $event->id)->where('status', EventAttendance::CHECKED_OUT)->count();

        return [
            'booked' => $booked,
            'checked_in' => $present,
            'absent' => max(0, $booked - $present),
            'checked_out' => $out,
            'checkout_enabled' => (bool) $event->checkout_enabled,
        ];
    }

    public function assertStaff(User $user): void
    {
        if (! $user->is_active || ! ($user->can('checkin.staff') || $user->can('checkin.manage'))) {
            throw new ApiException(403, 'FORBIDDEN', '只有授權工作人員可以使用此功能。');
        }
    }

    /**
     * Deletes check-in records (attendances and their admin corrections) past the
     * App's retention (D24, D28). The audit log stays append-only: check-in audit
     * entries never contain attendee identifiers or reasons (see audit()), so
     * nothing has to be rewritten; the run itself is recorded as evidence.
     *
     * @return array{attendances_deleted: int, corrections_deleted: int, retention_days: int, cutoff: string}
     */
    public function purge(): array
    {
        $days = $this->settings->int('checkin_retention_days');
        $cutoff = now()->subDays($days);
        $ids = EventAttendance::query()->where('updated_at', '<', $cutoff)
            ->whereHas('event', fn ($q) => $q->where('ends_at', '<', $cutoff))->pluck('id');

        // The deletions and their evidence entry commit together: no purge without
        // its audit record, no audit record for a purge that did not happen.
        return DB::transaction(function () use ($ids, $days, $cutoff): array {
            $corrections = 0;
            foreach ($ids->chunk(500) as $chunk) {
                $corrections += DB::table('event_attendance_corrections')->whereIn('event_attendance_id', $chunk)->delete();
                EventAttendance::query()->whereIn('id', $chunk)->delete();
            }

            $result = [
                'attendances_deleted' => $ids->count(),
                'corrections_deleted' => $corrections,
                'retention_days' => $days,
                'cutoff' => $cutoff->toIso8601ZuluString(),
            ];
            AuditEvent::record('checkin.retention_purge', null, null, $result);

            return $result;
        });
    }

    /**
     * Check-in audit entry without personal data (D28): staff and admins are named
     * as actors (accountability), a member checking in themselves is not; the
     * subject is the attendance row, which is deleted at retention.
     *
     * @param  array<string, mixed>  $details
     */
    private function audit(string $event, User $actor, EventAttendance $attendance, array $details): void
    {
        $staff = $actor->can('checkin.staff') || $actor->can('checkin.manage');
        AuditEvent::record($event, $staff ? $actor->id : null, $attendance, $details + ['actor_role' => $staff ? 'staff' : 'member']);
    }

    /**
     * @return array{attendee_id: string, result: string, attendance: EventAttendance}
     */
    private function checkIn(CalendarEvent $event, EventBookingAttendee $attendee, User $actor, string $source): array
    {
        if (! $event->checkin_enabled || $event->status !== CalendarEvent::STATUS_PUBLISHED) {
            throw new ApiException(409, 'CHECKIN_DISABLED', '此場次未開放簽到。');
        }
        if ($attendee->status === EventBookingAttendee::STATUS_WAITLISTED) {
            throw new ApiException(409, 'WAITLISTED', '候補尚未轉為正式報名，無法簽到。');
        }
        if ($attendee->status !== EventBookingAttendee::STATUS_CONFIRMED) {
            throw new ApiException(409, 'NOT_CONFIRMED', '此報名已取消，無法簽到。');
        }
        if (! now()->between($event->checkinOpensAt(), $event->checkinClosesAt())) {
            throw new ApiException(409, 'CHECKIN_CLOSED', '不在簽到時間內；逾時補登請洽管理員。');
        }

        try {
            [$attendance, $result] = DB::transaction(function () use ($event, $attendee, $actor, $source): array {
                $row = EventAttendance::query()->where('calendar_event_id', $event->id)->where('event_booking_attendee_id', $attendee->id)->lockForUpdate()->first();
                if ($row !== null && $row->status === EventAttendance::CHECKED_IN) {
                    return [$row, 'already_checked_in'];
                }
                if ($row !== null && $row->status === EventAttendance::CHECKED_OUT) {
                    return [$row, 'already_checked_out']; // D27: only an admin can correct
                }

                $row ??= new EventAttendance(['calendar_event_id' => $event->id, 'event_booking_attendee_id' => $attendee->id]);
                $row->forceFill([
                    'status' => EventAttendance::CHECKED_IN,
                    'checked_in_at' => now(),
                    'checked_out_at' => null,
                    'source' => $source,
                    'checked_in_by' => $actor->id,
                ])->save();
                // Same transaction: no check-in without its audit entry.
                $this->audit('checkin.checked_in', $actor, $row, ['event_id' => $event->id, 'source' => $source]);

                return [$row, 'checked_in'];
            });
        } catch (QueryException $e) {
            // UNIQUE(calendar_event_id, event_booking_attendee_id) from a parallel scan:
            // the other request's row exists. Any other failure is not hidden.
            // Only an actual unique-key race can be treated as a duplicate scan.
            // An audit INSERT failure must propagate even if a revoked row exists.
            $duplicateKey = ($e->errorInfo[1] ?? null) === 1062
                || (in_array((string) $e->getCode(), ['23000', '19'], true)
                    && str_contains(strtolower($e->getMessage()), 'unique'));
            if (! $duplicateKey) {
                throw $e;
            }
            $attendance = EventAttendance::query()->where('calendar_event_id', $event->id)
                ->where('event_booking_attendee_id', $attendee->id)->first();
            if ($attendance === null) {
                throw $e;
            }
            $result = $attendance->status === EventAttendance::CHECKED_OUT
                ? 'already_checked_out' : 'already_checked_in';
        }

        return ['attendee_id' => (string) $attendee->id, 'result' => $result, 'attendance' => $attendance];
    }

    /**
     * @param  array{lat?: float|null, lng?: float|null, accuracy?: float|null}  $gps
     */
    private function assertGps(CalendarEvent $event, CheckinLocation $location, array $gps): void
    {
        // Event setting first, then the App default (D26).
        $required = $event->checkin_gps_required ?? $this->settings->bool('checkin_gps_required');
        if (! $required) {
            return;
        }
        $radius = $event->checkin_gps_radius_m ?? $this->settings->int('checkin_gps_radius_m');

        if (! isset($gps['lat'], $gps['lng']) || $location->latitude === null || $location->longitude === null) {
            throw new ApiException(422, 'GPS_REQUIRED', '此場次需要定位才能簽到；定位失敗請洽現場工作人員協助。');
        }

        // The allowed distance is the configured radius (D20). A fix less precise than
        // the radius cannot prove presence: ask for a better fix or staff help (D17).
        $accuracy = (float) ($gps['accuracy'] ?? 0);
        if ($accuracy > $radius) {
            throw new ApiException(422, 'GPS_INACCURATE', '定位精確度不足（約 '.(int) round($accuracy).' 公尺），請到空曠處重試或洽現場工作人員協助簽到。');
        }
        $distance = self::distanceMeters((float) $gps['lat'], (float) $gps['lng'], $location->latitude, $location->longitude);
        if ($distance > $radius) {
            throw new ApiException(422, 'GPS_OUT_OF_RANGE', '您不在簽到範圍內（約 '.(int) round($distance).' 公尺）；如有問題請洽現場工作人員。');
        }
    }

    /**
     * The contact a guest states must equal the booking's email or phone (normalised).
     */
    public static function assertGuestContact(EventBookingGroup $group, ?string $contact): void
    {
        $contact = trim((string) $contact);
        if ($contact === '') {
            throw new ApiException(422, 'CONTACT_REQUIRED', '訪客請提供報名時填寫的 Email 或手機。', ['contact' => ['請輸入訪客報名時的 Email 或手機。']]);
        }
        $email = GuestClaimService::normalizeEmail($contact);
        $phone = GuestClaimService::normalizePhone($contact);
        $matches = ($group->contact_email !== null && hash_equals($group->contact_email, (string) $email))
            || ($group->contact_phone !== null && $phone !== null && hash_equals($group->contact_phone, $phone));
        if (! $matches) {
            throw new ApiException(422, 'CONTACT_MISMATCH', '聯絡資料與報名不符，請再確認。', ['contact' => ['與報名的 Email 或手機不符。']]);
        }
    }

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
