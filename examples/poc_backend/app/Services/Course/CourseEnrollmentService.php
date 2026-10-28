<?php

namespace App\Services\Course;

use App\Models\User;
use App\Services\Family\FamilyDelegation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CourseEnrollmentService
{
    public function __construct(private readonly FamilyDelegation $delegation) {}

    /**
     * A single administrator confirms an offline payment. Repeat calls are safe:
     * confirmed enrollments remain confirmed without duplicate transitions.
     * Session bookings are deliberately not created here until the shared seat
     * ledger integration is implemented atomically.
     */
    public function confirmFee(int $enrollmentId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可確認收費。']);
        }

        return DB::transaction(function () use ($enrollmentId): array {
            $entry = DB::table('course_enrollments')->where('id', $enrollmentId)->first();
            if ($entry === null) {
                throw ValidationException::withMessages(['enrollment' => '報名紀錄不存在。']);
            }

            DB::table('course_terms')->where('id', $entry->course_term_id)->lockForUpdate()->first();
            $entry = DB::table('course_enrollments')->where('id', $enrollmentId)->lockForUpdate()->first();

            if ($entry->status === 'confirmed') {
                return ['id' => $enrollmentId, 'status' => 'confirmed'];
            }
            if ($entry->status !== 'pending_payment') {
                throw ValidationException::withMessages(['enrollment' => '只有待收費報名可以確認。']);
            }

            // Do not mark paid before every session booking can be created atomically.
            // This safeguard is temporary until the shared BookingEngine integration.
            throw ValidationException::withMessages([
                'enrollment' => '整期課程尚未接通單堂預約，暫不可確認收費。',
            ]);
        }, 3);
    }

    /**
     * Read-only fee confirmation readiness. Never records a payment or books seats.
     * The administrator can correct missing sessions before confirming a term.
     */
    public function confirmationReadiness(int $enrollmentId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可查看收費確認狀態。']);
        }

        $entry = DB::table('course_enrollments')->where('id', $enrollmentId)->first();
        if ($entry === null) {
            throw ValidationException::withMessages(['enrollment' => '報名紀錄不存在。']);
        }

        $sessionIds = DB::table('course_sessions')
            ->where('course_term_id', $entry->course_term_id)
            ->orderBy('id')
            ->pluck('calendar_event_id')
            ->all();

        $events = DB::table('calendar_events')
            ->whereIn('id', $sessionIds)
            ->get(['id', 'status', 'booking_enabled', 'booking_capacity'])
            ->keyBy('id');
        $invalidSessionIds = [];
        foreach ($sessionIds as $eventId) {
            $event = $events->get($eventId);
            if ($event === null || $event->status !== 'published'
                || ! $event->booking_enabled || (int) $event->booking_capacity < 1) {
                $invalidSessionIds[] = $eventId;
            }
        }

        return [
            'id' => $enrollmentId,
            'status' => $entry->status,
            'session_count' => count($sessionIds),
            'invalid_session_event_ids' => $invalidSessionIds,
            'ready' => false,
            'reason' => count($sessionIds) === 0
                ? '本期尚未設定單堂課程。'
                : ($invalidSessionIds !== []
                    ? '部分單堂課程未發布或未開放預約。'
                    : '單堂 Booking 原子性整合尚未完成，禁止確認收費。'),
        ];
    }

    /**
     * One term seat per verified member. Lock the term before capacity checks.
     * Guardian delegation is verified inside the same transaction.
     */
    public function enroll(int $termId, User $booker, User $member): array
    {
        if (! $member->is_active || ! $member->isVerifiedMember()) {
            throw ValidationException::withMessages(['member' => '僅限有效且已驗證會員報名。']);
        }

        return DB::transaction(function () use ($termId, $booker, $member): array {
            $term = DB::table('course_terms')->where('id', $termId)->lockForUpdate()->first();
            if ($term === null) {
                throw ValidationException::withMessages(['term' => '課程期別不存在。']);
            }

            if (! $this->delegation->canManage($booker, $member, true)) {
                throw ValidationException::withMessages(['member' => '會員資格或代管權限不符。']);
            }

            $existing = DB::table('course_enrollments')
                ->where('course_term_id', $termId)->where('user_id', $member->id)->first();
            if ($existing !== null) {
                throw ValidationException::withMessages(['member' => '此會員已報名或曾報名本期，請由管理員處理。']);
            }

            $occupied = DB::table('course_enrollments')->where('course_term_id', $termId)
                ->whereIn('status', ['pending_payment', 'confirmed'])->count();
            $waiting = DB::table('course_enrollments')->where('course_term_id', $termId)
                ->where('status', 'waitlisted')->count();

            if ($occupied < $term->enrollment_capacity && $waiting === 0) {
                $status = 'pending_payment';
                $sequence = null;
            } elseif ($waiting < $term->waitlist_capacity) {
                $status = 'waitlisted';
                $sequence = (int) DB::table('course_enrollments')
                    ->where('course_term_id', $termId)->max('waitlist_sequence') + 1;
            } else {
                throw ValidationException::withMessages(['term' => '本期課程及候補名額皆已滿。']);
            }

            $id = DB::table('course_enrollments')->insertGetId([
                'course_term_id' => $termId,
                'user_id' => $member->id,
                'booker_user_id' => $booker->id,
                'status' => $status,
                'waitlist_sequence' => $sequence,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'status' => $status, 'waitlist_sequence' => $sequence];
        }, 3);
    }
}
