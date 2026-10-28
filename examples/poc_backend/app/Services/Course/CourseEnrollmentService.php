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

            return ['id' => $id, 'status' => $status, 'waitlist_sequence' => $sequence];
        }, 3);
    }
}
