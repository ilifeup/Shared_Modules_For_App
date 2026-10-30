<?php

namespace App\Services\Course;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CourseEnrollment;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Payment\CashInvoiceService;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class CourseEnrollmentService
{
    public function __construct(
        private readonly BookingEngine $bookings,
    ) {}

    /**
     * Compatibility endpoint: only the cash-invoice service may confirm payment.
     * No direct course state transition or session booking is allowed here.
     */
    public function confirmFee(int $enrollmentId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可確認收費。']);
        }

        throw ValidationException::withMessages([
            'invoice' => '舊版課程收費確認已停用；請使用整期帳單確認現金收款。',
        ]);
    }

    /**
     * Business-facing name: administrator checks that cash has been received.
     * Keep confirmFee() as a compatibility entry point until booking and payment
     * responsibilities are separated in the next migration.
     */
    public function paidCheck(int $enrollmentId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', '僅管理員可確認收費。');
        }

        $invoice = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollmentId)->first();
        if ($invoice !== null) {
            $result = app(CashInvoiceService::class)->confirmCash((int) $invoice->id, $admin);

            // Report the stored enrollment state: a replay for an invoice paid
            // before the member cancelled must not claim the term is confirmed.
            return ['id' => $enrollmentId,
                'status' => (string) DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'),
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

        // Payment readiness checks existing derived bookings, not whether a
        // member could independently book each occurrence a second time.
        $invalidSessionIds = [];
        foreach ($sessionIds as $eventId) {
            $requestId = (string) Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'course-enrollment:'.$entry->id.':event:'.$eventId,
            );
            $group = EventBookingGroup::query()
                ->where('client_request_id', $requestId)
                ->where('calendar_event_id', $eventId)
                ->where('status', EventBookingGroup::STATUS_CONFIRMED)
                ->first();
            $attendees = $group?->attendees()->where('active', true)->get();
            if ($group === null || (int) $group->booker_user_id !== (int) $entry->booker_user_id
                || (int) $group->seat_count !== 1 || $attendees->count() !== 1
                || $attendees->first()->status !== 'confirmed'
                || (int) $attendees->first()->member_user_id !== (int) $entry->user_id) {
                $invalidSessionIds[] = $eventId;
            }
        }

        // Advisory preflight must reflect the same database membership rules as confirmFee().
        // The transactional confirmFee() remains the final authority.
        $member = User::query()->find($entry->user_id);
        $booker = User::query()->find($entry->booker_user_id);
        $eligible = $member !== null && $booker !== null
            && $this->bookings->canManageFamily($booker, $member);

        // Advisory PaidCheck must not offer confirmation for a missing, expired
        // or mismatched term invoice. confirmCash() rechecks under DB locks.
        $invoice = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollmentId)->first();
        $invoiceReady = $invoice !== null
            && (int) $invoice->invoice_status === 0
            && (int) $invoice->product_id === (int) $entry->course_term_id
            && (int) $invoice->member_id === (int) $entry->user_id
            && (int) $invoice->booker_user_id === (int) $entry->booker_user_id
            && (float) $invoice->invoice_amount >= 1
            && $invoice->currency === 'TWD'
            && $invoice->payment_due_at !== null
            && ! now()->gt($invoice->payment_due_at);

        $ready = $entry->status === 'pending_payment' && $sessionIds !== []
            && $invalidSessionIds === [] && $eligible && $invoiceReady;

        return [
            'id' => $enrollmentId,
            'status' => $entry->status,
            'session_count' => count($sessionIds),
            'invalid_session_event_ids' => array_values(array_unique($invalidSessionIds)),
            'ready' => $ready,
            'reason' => count($sessionIds) === 0
                ? '本期尚未設定單堂課程。'
                : ($invalidSessionIds !== []
                    ? '部分從屬場次預約關聯不存在或尚未確認。'
                    : (! $eligible ? '會員資格或代管權限不符。'
                        : (! $invoiceReady ? '整期帳單不存在、已逾期或與報名資料不符。'
                            : ($ready ? '從屬場次預約已建立，可確認實際現金收款。' : '此報名目前不可確認收費。')))),
        ];
    }

    /**
     * Cancel a waitlisted course enrollment through the member API. A retry of a
     * completed waitlist cancellation returns the same result without effects.
     */
    public function cancelWaitlist(int $enrollmentId, User $actor): array
    {
        return DB::transaction(function () use ($enrollmentId, $actor): array {
            [$term, $entry] = $this->lockOwnEnrollment($enrollmentId, $actor, '目前無權取消此會員的報名。');
            // A cancelled waiter keeps its sequence; a promoted one never has one.
            if ($entry->status === 'cancelled' && $entry->waitlist_sequence !== null) {
                return ['id' => (int) $entry->id, 'status' => 'cancelled', 'replayed' => true];
            }
            if ($entry->status !== 'waitlisted') {
                throw ValidationException::withMessages(['enrollment' => '目前僅支援取消候補中的課程報名。']);
            }
            if (DB::table('invoices')->where('booking_type', '02')->where('booking_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => '候補報名存在帳單，請管理員檢查。']);
            }
            DB::table('course_enrollments')->where('id', $entry->id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->auditCancellation($entry, $actor, null);
            // A withdrawn head may have been the only thing blocking FIFO.
            app(CashInvoiceService::class)->fillCourseWaitlistAfterCapacityChange((int) $term->id);

            return ['id' => (int) $entry->id, 'status' => 'cancelled', 'replayed' => false];
        }, 3);
    }

    /**
     * API owns enrollment cancellation; back-office owns any actual refund.
     * Lock order: term -> enrollment -> invoice -> derived occurrences, the same
     * as enrollment, cash confirmation and expiry. A retry of an already
     * cancelled enrollment returns the same result without releasing seats,
     * touching invoices or promoting waiters again (BOOKING-LOCK-001).
     */
    public function cancel(int $enrollmentId, User $actor): array
    {
        return DB::transaction(function () use ($enrollmentId, $actor): array {
            [$term, $entry] = $this->lockOwnEnrollment($enrollmentId, $actor, '目前無權取消此會員的課程報名。');
            if ($entry->status === 'cancelled') {
                return ['id' => (int) $entry->id, 'status' => 'cancelled', 'replayed' => true];
            }
            if (! in_array($entry->status, ['pending_payment', 'confirmed', 'waitlisted'], true)) {
                throw ValidationException::withMessages(['enrollment' => '此報名不可取消。']);
            }
            $firstStart = DB::table('course_sessions')
                ->join('calendar_events', 'calendar_events.id', '=', 'course_sessions.calendar_event_id')
                ->where('course_sessions.course_term_id', $term->id)->min('calendar_events.starts_at');
            if ($firstStart === null || now()->gte(Carbon::parse($firstStart)
                ->subDays((int) $term->cancel_cutoff_days))) {
                throw ValidationException::withMessages(['enrollment' => '已超過整期課程取消期限，請洽管理員。']);
            }
            $invoice = DB::table('invoices')->where('booking_type', '02')
                ->where('booking_id', $entry->id)->lockForUpdate()->first();
            if ($entry->status === 'waitlisted' && $invoice !== null) {
                throw ValidationException::withMessages(['invoice' => '候補報名帳單異常，請洽管理員。']);
            }
            if ($entry->status !== 'waitlisted' && ($invoice === null
                || ! in_array((int) $invoice->invoice_status, [0, 1], true))) {
                throw ValidationException::withMessages(['invoice' => '帳單狀態異常，請洽管理員。']);
            }
            // Only the term invoice of this exact enrollment may be cancelled
            // or left for refund; never act on a mismatched invoice row.
            if ($invoice !== null && ($invoice->transaction_type !== '02'
                || (int) $invoice->product_id !== (int) $term->id
                || (int) $invoice->member_id !== (int) $entry->user_id
                || (int) $invoice->booker_user_id !== (int) $entry->booker_user_id)) {
                throw ValidationException::withMessages(['invoice' => '整期帳單與報名資料不符，請洽管理員。']);
            }
            DB::table('course_enrollments')->where('id', $entry->id)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            // Unpaid: the invoice is cancelled with the seat. Paid: it stays paid
            // (status 1) for a separate administrator refund; never auto-refund.
            if ($invoice !== null && (int) $invoice->invoice_status === 0) {
                DB::table('invoices')->where('id', $invoice->id)
                    ->update(['invoice_status' => 2, 'updated_at' => now()]);
            }
            $this->auditCancellation($entry, $actor, $invoice);
            if ($entry->status !== 'waitlisted') {
                // Every derived occurrence is released in this transaction; any
                // failure rolls back the cancellation and invoice change too.
                app(CashInvoiceService::class)->releaseCourseBookings($entry, 'course_enrollment_cancelled');
            }
            app(CashInvoiceService::class)->fillCourseWaitlistAfterCapacityChange((int) $term->id);

            return ['id' => (int) $entry->id, 'status' => 'cancelled', 'replayed' => false];
        }, 3);
    }

    /**
     * Record a single-session leave without cancelling the term or refunding.
     * The term and enrollment are locked in the same order as cancellation.
     */
    public function requestSessionLeave(int $enrollmentId, int $sessionId, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($enrollmentId, $sessionId, $actor, $reason): array {
            [$term, $entry] = $this->lockOwnEnrollment($enrollmentId, $actor, '目前無權為此會員請假。');
            if (! in_array($entry->status, ['pending_payment', 'confirmed'], true)) {
                throw ValidationException::withMessages(['enrollment' => '只有有效整期報名可以申請單堂請假。']);
            }
            $session = DB::table('course_sessions as s')
                ->join('calendar_events as e', 'e.id', '=', 's.calendar_event_id')
                ->where('s.id', $sessionId)->where('s.course_term_id', $term->id)
                ->select('s.id', 'e.starts_at')->first();
            if ($session === null) {
                throw ValidationException::withMessages(['session' => '此課堂不屬於本期課程。']);
            }
            $existing = DB::table('course_session_leaves')
                ->where('course_enrollment_id', $entry->id)->where('course_session_id', $sessionId)->first();
            if ($existing !== null) {
                return ['id' => (int) $existing->id, 'session_id' => $sessionId, 'replayed' => true];
            }
            if (now()->gte(Carbon::parse($session->starts_at)->subHours(48))) {
                throw ValidationException::withMessages(['session' => '距離開課不足 48 小時，無法自行請假。']);
            }
            $id = DB::table('course_session_leaves')->insertGetId([
                'course_enrollment_id' => $entry->id,
                'course_session_id' => $sessionId,
                'reason' => $reason,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditEvent::record('course.session_leave_requested', $actor->id,
                CourseEnrollment::query()->findOrFail($entry->id), ['session_id' => $sessionId]);

            return ['id' => (int) $id, 'session_id' => $sessionId, 'replayed' => false];
        }, 3);
    }

    /**
     * Lock the term then the enrollment (shared lock order) and require the
     * original booker with current database authority over the member. The same
     * check guards retries, so a revoked delegation cannot replay either.
     *
     * @return array{0: object, 1: object}
     */
    private function lockOwnEnrollment(int $enrollmentId, User $actor, string $forbidden): array
    {
        $hint = DB::table('course_enrollments')->where('id', $enrollmentId)->first();
        if ($hint === null || (int) $hint->booker_user_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['enrollment' => '找不到可取消的報名。']);
        }
        $term = DB::table('course_terms')->where('id', $hint->course_term_id)->lockForUpdate()->first();
        if ($term === null) {
            throw ValidationException::withMessages(['term' => '課程期別不存在。']);
        }
        $entry = DB::table('course_enrollments')->where('id', $enrollmentId)->lockForUpdate()->first();
        if ($entry === null || (int) $entry->course_term_id !== (int) $term->id
            || (int) $entry->booker_user_id !== (int) $actor->id) {
            throw ValidationException::withMessages(['enrollment' => '找不到可取消的報名。']);
        }
        $member = User::query()->find($entry->user_id);
        if ($member === null || ! $this->bookings->canManageFamily($actor, $member, true)) {
            throw ValidationException::withMessages(['member' => $forbidden]);
        }

        return [$term, $entry];
    }

    /** De-identified evidence of who cancelled and what happened to the invoice. */
    private function auditCancellation(object $entry, User $actor, ?object $invoice): void
    {
        AuditEvent::record('course.enrollment_cancelled', $actor->id,
            CourseEnrollment::query()->find($entry->id), [
                'course_term_id' => (int) $entry->course_term_id,
                'from_status' => $entry->status,
                'invoice_id' => $invoice !== null ? (int) $invoice->id : null,
                'invoice_status_before' => $invoice !== null ? (int) $invoice->invoice_status : null,
                'invoice_status_after' => $invoice === null ? null
                    : ((int) $invoice->invoice_status === 0 ? 2 : (int) $invoice->invoice_status),
                'by' => (int) $actor->id === (int) $entry->user_id ? 'member' : 'family_manager',
            ]);
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

            // A term opens only at its administrator-configured instant.
            // Check before idempotent replay, so early requests cannot obtain a seat.
            if ($term->booking_opens_at !== null
                && now()->lt(Carbon::parse($term->booking_opens_at))) {
                throw ValidationException::withMessages(['term' => '本期課程尚未開放預約。']);
            }

            if ($term->booking_closes_at !== null
                && now()->gte(Carbon::parse($term->booking_closes_at))) {
                throw ValidationException::withMessages(['term' => '本期課程已停止接受預約。']);
            }
            if ($term->booking_opens_at !== null && $term->booking_closes_at !== null
                && Carbon::parse($term->booking_closes_at)->lte(Carbon::parse($term->booking_opens_at))) {
                throw ValidationException::withMessages(['term' => '預約截止時間必須晚於開放時間。']);
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
                // A retry (double tap, timeout) by the same authorized booker
                // returns the existing place: no second seat, invoice or booking.
                if ((int) $existing->booker_user_id === (int) $booker->id
                    && in_array($existing->status, ['pending_payment', 'confirmed', 'waitlisted'], true)) {
                    return ['id' => (int) $existing->id, 'status' => $existing->status,
                        'waitlist_sequence' => $existing->waitlist_sequence === null ? null : (int) $existing->waitlist_sequence,
                        'replayed' => true];
                }
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
                $sessions = DB::table('course_sessions')
                    ->where('course_term_id', $termId)->orderBy('calendar_event_id')
                    ->pluck('calendar_event_id')->all();
                if ($sessions === []) {
                    throw ValidationException::withMessages(['session' => '本期尚未安排課堂，無法報名。']);
                }

                // Existing BookingEngine retains all seat and member validation.
                // One outer transaction rolls back the enrollment and every
                // session booking if any session is unavailable.
                foreach ($sessions as $eventId) {
                    $event = CalendarEvent::query()->findOrFail($eventId);
                    if ($event->status !== 'published' || ! $event->booking_enabled
                        || (int) $event->booking_capacity < 1) {
                        throw ValidationException::withMessages([
                            'session' => '系列活動／課程有未發布、未開放預約或名額設定無效的從屬場次。',
                        ]);
                    }
                    $group = $this->bookings->book($event, new BookingRequest(
                        booker: $booker,
                        contactName: $booker->name,
                        contactEmail: $booker->email,
                        contactPhone: $booker->phone_e164,
                        attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
                        clientRequestId: (string) Uuid::uuid5(
                            Uuid::NAMESPACE_URL,
                            'course-enrollment:'.$id.':event:'.$eventId,
                        ),
                        byAdmin: true,
                    ), coveredByCourseTerm: true);
                    if ($group->status !== EventBookingGroup::STATUS_CONFIRMED) {
                        throw ValidationException::withMessages(['session' => '課程預約未完成，請聯絡管理員。']);
                    }
                }

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

            return ['id' => $id, 'status' => $status, 'waitlist_sequence' => $sequence, 'replayed' => false];
        }, 3);
    }
}
