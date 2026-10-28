<?php

namespace App\Services\Course;

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Payment\CashInvoiceService;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class CourseEnrollmentService
{
    public function __construct(
        private readonly BookingEngine $bookings,
    ) {}

    /**
     * A single administrator confirms an offline payment. Repeat calls are safe:
     * confirmed enrollments remain confirmed without duplicate transitions.
     * Every session booking and the fee confirmation share one DB transaction.
     */
    public function confirmFee(int $enrollmentId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可確認收費。']);
        }

        // Legacy entry point must not bypass the real cash invoice ledger.
        // The integrated course booking/payment migration will replace this
        // method after its session-booking atomicity is verified.
        throw ValidationException::withMessages([
            'invoice' => '舊版課程收費確認已停用；請使用整期帳單確認現金收款。',
        ]);

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

            $sessions = DB::table('course_sessions')
                ->where('course_term_id', $entry->course_term_id)
                ->orderBy('id')->pluck('calendar_event_id')->all();
            if ($sessions === []) {
                throw ValidationException::withMessages(['enrollment' => '本期尚未設定單堂課程。']);
            }

            $member = User::query()->findOrFail($entry->user_id);
            $booker = User::query()->findOrFail($entry->booker_user_id);
            if (! $member->is_active || (int) $member->verified_status !== 2
                || ! $booker->is_active || (int) $booker->verified_status !== 2) {
                throw ValidationException::withMessages(['member' => '會員資格已失效，無法確認整期課程。']);
            }

            // A guardian's authority may have ended after term enrollment.
            // Recheck the current database relationship before any session seat is booked.
            if (! $this->bookings->canManageFamily($booker, $member, true)) {
                throw ValidationException::withMessages(['member' => '家庭代管權限已失效，無法確認整期課程。']);
            }

            foreach ($sessions as $eventId) {
                $event = CalendarEvent::query()->findOrFail($eventId);
                // Course confirmation must never create a waitlist place.
                if ($this->bookings->unavailableReason($event, 1, true) !== null) {
                    throw ValidationException::withMessages(['session' => '單堂課程無法完成正式預約。']);
                }
                $group = $this->bookings->book($event, new BookingRequest(
                    booker: $booker,
                    contactName: $booker->name,
                    contactEmail: $booker->email,
                    contactPhone: $booker->phone_e164,
                    attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
                    clientRequestId: (string) Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        'course-enrollment:'.$enrollmentId.':event:'.$eventId,
                    ),
                    byAdmin: true,
                ));
                if ($group->status !== EventBookingGroup::STATUS_CONFIRMED) {
                    throw ValidationException::withMessages(['session' => '單堂課程未取得正式名額。']);
                }
            }

            DB::table('course_enrollments')->where('id', $enrollmentId)->update([
                'status' => 'confirmed',
                'fee_confirmed_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $enrollmentId, 'status' => 'confirmed'];
        }, 3);
    }

    /**
     * Business-facing name: administrator checks that cash has been received.
     * Keep confirmFee() as a compatibility entry point until booking and payment
     * responsibilities are separated in the next migration.
     */
    public function paidCheck(int $enrollmentId, User $admin): array
    {
        $invoice = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollmentId)->first();
        if ($invoice !== null) {
            $result = app(CashInvoiceService::class)->confirmCash((int) $invoice->id, $admin);
            return ['id' => $enrollmentId, 'status' => 'confirmed',
                'invoice_id' => (int) $invoice->id, 'replayed' => $result['replayed']];
        }
        // Paid course terms require a real invoice. Never fall back to the
        // legacy fee-confirmation path, which could confirm without cash.
        throw ValidationException::withMessages([
            'invoice' => '整期課程尚未建立帳單，禁止確認收款；請由管理員檢查資料。',
        ]);
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

        // Advisory preflight must reflect the same database membership rules as confirmFee().
        // The transactional confirmFee() remains the final authority.
        $member = User::query()->find($entry->user_id);
        $booker = User::query()->find($entry->booker_user_id);
        $eligible = $member !== null && $booker !== null
            && $this->bookings->canManageFamily($booker, $member);

        $ready = $entry->status === 'pending_payment' && $sessionIds !== []
            && $invalidSessionIds === [] && $eligible;
        if ($ready) {
            foreach ($sessionIds as $eventId) {
                $event = CalendarEvent::query()->find($eventId);
                if ($event === null || $this->bookings->unavailableReason($event, 1, true) !== null) {
                    $ready = false;
                    $invalidSessionIds[] = $eventId;
                }
            }
        }

        return [
            'id' => $enrollmentId,
            'status' => $entry->status,
            'session_count' => count($sessionIds),
            'invalid_session_event_ids' => array_values(array_unique($invalidSessionIds)),
            'ready' => $ready,
            'reason' => count($sessionIds) === 0
                ? '本期尚未設定單堂課程。'
                : ($invalidSessionIds !== []
                    ? '部分單堂課程未發布、未開放預約或名額不足。'
                    : (! $eligible ? '會員資格或代管權限不符。' : ($ready ? '可確認線下收費並建立整期單堂預約。' : '此報名目前不可確認收費。'))),
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

            if ($term->price_amount === null || (float) $term->price_amount < 1) {
                throw ValidationException::withMessages(['price_amount' => '課程最低實收 NT$1。']);
            }

            if (! $this->bookings->canManageFamily($booker, $member, true)) {
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

            if ($status === 'pending_payment') {
                $hours = (int) ($term->payment_hold_hours ?? app(Settings::class)->int('payment_hold_hours'));
                $firstStart = DB::table('course_sessions')
                    ->join('calendar_events', 'calendar_events.id', '=', 'course_sessions.calendar_event_id')
                    ->where('course_sessions.course_term_id', $termId)
                    ->min('calendar_events.starts_at');
                if ($firstStart !== null && now()->copy()->addHours($hours)->gt($firstStart)) {
                    throw ValidationException::withMessages(['payment' => '剩餘時間不足以保留完整付款期限。']);
                }
                app(CashInvoiceService::class)->create('02', $termId, '02', $id,
                    $member->id, $booker->id, (string) $term->price_amount, $hours);
            }

            return ['id' => $id, 'status' => $status, 'waitlist_sequence' => $sequence];
        }, 3);
    }
}
