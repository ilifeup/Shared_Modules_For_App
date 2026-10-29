<?php

namespace App\Services\Payment;

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Cash-only MVP ledger. Invoke inside the caller's seat/term transaction.
 * An invoice belongs to a booking, not to an individual session of a term.
 */
final class CashInvoiceService
{
    public function forEvent(CalendarEvent $event, EventBookingGroup $group, int $memberId): void
    {
        if ((float) $event->price_amount <= 0) {
            return;
        }

        $hours = $event->payment_hold_hours ?? app(Settings::class)->int('payment_hold_hours');
        if (now()->copy()->addHours($hours)->gt($event->starts_at)) {
            throw ValidationException::withMessages(['payment' => '剩餘時間不足以保留完整付款期限，無法取得付費名額。']);
        }

        $this->create('01', $event->id, '01', $group->id, $memberId,
            (int) $group->booker_user_id, (string) $event->price_amount, $hours);
    }

    public function create(string $type, int $productId, string $bookingType, int $bookingId,
        int $memberId, int $bookerId, string $amount, int $hours): void
    {
        if (DB::table('invoices')->where('booking_type', $bookingType)->where('booking_id', $bookingId)->exists()) {
            return;
        }
        $id = DB::table('invoices')->insertGetId([
            'invoice_code' => 'TMP-'.Str::uuid(),
            'invoice_status' => 0,
            'invoice_amount' => $amount,
            'currency' => 'TWD',
            'member_id' => $memberId,
            'booker_user_id' => $bookerId,
            'transaction_type' => $type,
            'product_id' => $productId,
            'booking_type' => $bookingType,
            'booking_id' => $bookingId,
            'app_stage' => 0,
            'payment_method' => 0,
            'invoice_datetime' => now(),
            'payment_due_at' => now()->addHours($hours),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoices')->where('id', $id)->update([
            'invoice_code' => $type.now()->format('Ymd').'-'.str_pad((string) $id, 9, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * A bounded scan; every row is rechecked under the event and invoice locks.
     * The scheduler can safely retry interrupted batches.
     */
    public function expireDue(int $limit = 100): int
    {
        $due = DB::table('invoices')->where('invoice_status', 0)
            ->where('booking_type', '01')->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())->orderBy('id')->limit($limit)
            ->get(['id', 'booking_id']);
        $expired = 0;
        foreach ($due as $invoice) {
            if (app(BookingEngine::class)->expireCashHold((int) $invoice->booking_id, (int) $invoice->id)) {
                $expired++;
            }
        }

        // Course expiration must also release its session bookings before a waitlist promotion.
        $courseDue = DB::table('invoices')->where('invoice_status', 0)
            ->where('booking_type', '02')->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())->orderBy('id')->limit($limit)
            ->get(['id', 'booking_id']);
        foreach ($courseDue as $item) {
            $didExpire = DB::transaction(function () use ($item): bool {
                // Resolve the parent without a write lock, then acquire locks
                // in the shared order: term -> enrollment -> invoice.
                $hint = DB::table('invoices')->where('id', $item->id)->first();
                if ($hint === null || $hint->booking_type !== '02') {
                    return false;
                }
                $termId = DB::table('course_enrollments')->where('id', $hint->booking_id)
                    ->value('course_term_id');
                if ($termId === null || DB::table('course_terms')->where('id', $termId)
                    ->lockForUpdate()->first() === null) {
                    return false;
                }
                $entry = DB::table('course_enrollments')->where('id', $hint->booking_id)
                    ->lockForUpdate()->first();
                $invoice = DB::table('invoices')->where('id', $item->id)->lockForUpdate()->first();
                if ($entry === null || (int) $entry->course_term_id !== (int) $termId
                    || $entry->status !== 'pending_payment'
                    || $invoice === null || $invoice->booking_type !== '02'
                    || (int) $invoice->booking_id !== (int) $entry->id
                    || (int) $invoice->invoice_status !== 0
                    || $invoice->payment_due_at === null || now()->lt($invoice->payment_due_at)) {
                    return false;
                }
                DB::table('course_enrollments')->where('id', $entry->id)
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('invoices')->where('id', $invoice->id)
                    ->update(['invoice_status' => 3, 'updated_at' => now()]);
                $this->releaseCourseBookings($entry);
                // Same FIFO fill as cancellation and capacity changes (term still locked).
                $this->fillCourseWaitlistAfterCapacityChange((int) $entry->course_term_id);

                return true;
            }, 3);
            if ($didExpire) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Release the session seats held by an expired whole-term enrollment.
     * BookingEngine owns all seat and waitlist updates.
     */
    public function releaseCourseBookings(object $entry, string $reason = 'course_payment_expired'): void
    {
        $actor = User::query()->findOrFail($entry->booker_user_id);
        $events = DB::table('course_sessions')
            ->where('course_term_id', $entry->course_term_id)
            ->orderBy('calendar_event_id')->pluck('calendar_event_id');

        foreach ($events as $eventId) {
            $requestId = (string) Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'course-enrollment:'.$entry->id.':event:'.$eventId,
            );
            $group = EventBookingGroup::query()
                ->where('client_request_id', $requestId)
                ->where('status', EventBookingGroup::STATUS_CONFIRMED)->first();
            if ($group !== null) {
                app(BookingEngine::class)->cancelGroup($group, $actor, true, $reason);
            }
        }

        // A whole-term cancellation also invalidates any separately assigned
        // makeup seats. Keep this inside the parent's database transaction so
        // the canonical M11 attendee ledger cannot retain an orphaned seat.
        $makeupGroups = DB::table('course_makeups as m')
            ->join('course_session_leaves as l', 'l.id', '=', 'm.course_session_leave_id')
            ->join('event_booking_groups as g', 'g.id', '=', 'm.event_booking_group_id')
            ->where('l.course_enrollment_id', $entry->id)
            ->where('g.status', EventBookingGroup::STATUS_CONFIRMED)
            ->orderBy('g.calendar_event_id')
            ->pluck('g.id');

        foreach ($makeupGroups as $groupId) {
            $group = EventBookingGroup::query()->findOrFail($groupId);
            app(BookingEngine::class)->cancelGroup($group, $actor, true, 'course_makeup_released');
        }
    }

    /**
     * Fill newly opened whole-term seats in strict FIFO order. The term lock
     * serializes administrator capacity changes and member enrollments.
     * An ineligible head blocks promotion; never skip their position.
     * Any booking failure rolls back capacity and derived bookings together.
     */
    public function fillCourseWaitlistAfterCapacityChange(int $termId): int
    {
        return DB::transaction(function () use ($termId): int {
            $term = DB::table('course_terms')->where('id', $termId)->lockForUpdate()->first();
            if ($term === null) {
                return 0;
            }

            $promoted = 0;
            while ($this->promoteCourseWaitlist($termId)) {
                $promoted++;
            }

            return $promoted;
        }, 3);
    }

    /**
     * Promote only the first waiting course member; never bypass an ineligible head.
     * Caller must hold the course-term lock.
     */
    private function promoteCourseWaitlist(int $termId): bool
    {
        $term = DB::table('course_terms')->where('id', $termId)->first();
        if ($term === null) {
            return false;
        }
        $occupied = DB::table('course_enrollments')->where('course_term_id', $termId)
            ->whereIn('status', ['pending_payment', 'confirmed'])->count();
        if ((float) ($term->price_amount ?? 0) < 1 || $occupied >= (int) $term->enrollment_capacity) {
            return false;
        }
        $head = DB::table('course_enrollments')->where('course_term_id', $termId)
            ->where('status', 'waitlisted')->orderBy('waitlist_sequence')->orderBy('id')
            ->lockForUpdate()->first();
        if ($head === null) {
            return false;
        }
        $hours = (int) ($term->payment_hold_hours ?? app(Settings::class)->int('payment_hold_hours'));
        $firstStart = DB::table('course_sessions')
            ->join('calendar_events', 'calendar_events.id', '=', 'course_sessions.calendar_event_id')
            ->where('course_sessions.course_term_id', $termId)
            ->min('calendar_events.starts_at');
        if ($firstStart === null || now()->copy()->addHours($hours)->gt($firstStart)) {
            return false;
        }
        $member = User::query()->find($head->user_id);
        $booker = User::query()->find($head->booker_user_id);
        if ($member === null || $booker === null || ! $member->is_active
            || ! $member->isVerifiedMember()
            || ! app(BookingEngine::class)->canManageFamily($booker, $member, true)) {
            return false;
        }
        // The term row is already locked by the caller. Reserve the FIFO
        // head as pending before creating derived session bookings; the booking
        // engine validates this exact pending enrollment and request ID.
        // Any failure rolls this transition back with the surrounding transaction.
        $engine = app(BookingEngine::class);
        $eventIds = DB::table('course_sessions')
            ->where('course_term_id', $termId)->orderBy('calendar_event_id')
            ->pluck('calendar_event_id');
        if ($eventIds->isEmpty()) {
            return false;
        }
        // Same session preconditions as a new enrollment. An invalid session
        // keeps the head waiting (FIFO is never skipped) instead of creating a
        // derived seat on a cancelled/draft occurrence or failing the caller's
        // cancellation, expiry or capacity change.
        foreach ($eventIds as $eventId) {
            $event = CalendarEvent::query()->find($eventId);
            if ($event === null || $event->status !== CalendarEvent::STATUS_PUBLISHED
                || ! $event->booking_enabled || (int) $event->booking_capacity < 1
                || $engine->activeAttendeeOf($event, $member) !== null) {
                return false;
            }
        }
        DB::table('course_enrollments')->where('id', $head->id)->update([
            'status' => 'pending_payment', 'waitlist_sequence' => null, 'updated_at' => now(),
        ]);
        foreach ($eventIds as $eventId) {
            $event = CalendarEvent::query()->findOrFail($eventId);
            $group = $engine->book($event, new BookingRequest(
                booker: $booker,
                contactName: $booker->name,
                contactEmail: $booker->email,
                contactPhone: $booker->phone_e164,
                attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
                clientRequestId: (string) Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'course-enrollment:'.$head->id.':event:'.$eventId,
                ),
                byAdmin: true,
            ), coveredByCourseTerm: true);
            if ($group->status !== EventBookingGroup::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['session' => '候補遞補課堂預約失敗，請聯絡管理員。']);
            }
        }
        $this->create('02', $termId, '02', (int) $head->id,
            (int) $head->user_id, (int) $head->booker_user_id,
            (string) $term->price_amount, $hours);

        return true;
    }

    /**
     * Manual cash refund only. Does not reassign an issued invoice number.
     * The operator must complete the real-world refund before recording it here.
     */
    public function recordManualRefund(int $invoiceId, User $admin, string $reason): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw new \App\Support\Api\ApiException(403, 'ADMIN_REQUIRED', '僅管理員可記錄退款。');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => '請填寫退款原因。']);
        }

        return DB::transaction(function () use ($invoiceId, $admin, $reason): array {
            // Match cash confirmation's parent-first lock order to prevent
            // refund/cancellation races and cross-service deadlocks.
            $hint = DB::table('invoices')->where('id', $invoiceId)->first();
            if ($hint === null) {
                throw ValidationException::withMessages(['invoice' => '帳單不存在。']);
            }

            if ($hint->booking_type === '01') {
                $groupHint = DB::table('event_booking_groups')->where('id', $hint->booking_id)->first();
                if ($groupHint === null || DB::table('calendar_events')
                    ->where('id', $groupHint->calendar_event_id)->lockForUpdate()->first() === null) {
                    throw ValidationException::withMessages(['invoice' => '預約或活動不存在。']);
                }
                $group = DB::table('event_booking_groups')->where('id', $hint->booking_id)
                    ->lockForUpdate()->first();
                if ($group === null || (int) $group->calendar_event_id !== (int) $groupHint->calendar_event_id
                    || ! in_array($group->status, [
                        EventBookingGroup::STATUS_CANCELLED,
                        EventBookingGroup::STATUS_EVENT_CANCELLED,
                    ], true)) {
                    throw ValidationException::withMessages(['invoice' => '必須先取消預約，才能記錄退款。']);
                }
            } elseif ($hint->booking_type === '02') {
                $termId = DB::table('course_enrollments')->where('id', $hint->booking_id)
                    ->value('course_term_id');
                if ($termId === null || DB::table('course_terms')->where('id', $termId)
                    ->lockForUpdate()->first() === null) {
                    throw ValidationException::withMessages(['invoice' => '整期課程報名不存在。']);
                }
                $enrollment = DB::table('course_enrollments')->where('id', $hint->booking_id)
                    ->lockForUpdate()->first();
                if ($enrollment === null || (int) $enrollment->course_term_id !== (int) $termId
                    || $enrollment->status !== 'cancelled') {
                    throw ValidationException::withMessages(['invoice' => '必須先取消整期課程報名，才能記錄退款。']);
                }
            } else {
                throw ValidationException::withMessages(['invoice' => '帳單預約類型不受支援。']);
            }

            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || $invoice->booking_type !== $hint->booking_type
                || (int) $invoice->booking_id !== (int) $hint->booking_id) {
                throw ValidationException::withMessages(['invoice' => '帳單資料已變更，請重新操作。']);
            }
            // A retried refund (double submit, timeout) returns the recorded
            // result; it never writes a second negative transaction.
            if ((int) $invoice->invoice_status === 5 && DB::table('invoice_refund_audits')
                ->where('invoice_id', $invoiceId)->exists()) {
                return ['id' => $invoiceId, 'status' => 5, 'replayed' => true];
            }
            if ((int) $invoice->invoice_status !== 1) {
                throw ValidationException::withMessages(['invoice' => '僅已付款且尚未退款的帳單可以退款。']);
            }
            // The invoice must belong to the cancelled parent it is refunded for.
            if ($invoice->booking_type === '01') {
                $memberIds = DB::table('event_booking_attendees')
                    ->where('event_booking_group_id', $group->id)->pluck('member_user_id')
                    ->map(static fn ($id): int => (int) $id)->all();
                $matches = $invoice->transaction_type === '01'
                    && (int) $invoice->product_id === (int) $group->calendar_event_id
                    && (int) $invoice->booker_user_id === (int) $group->booker_user_id
                    && in_array((int) $invoice->member_id, $memberIds, true);
            } else {
                $matches = $invoice->transaction_type === '02'
                    && (int) $invoice->product_id === (int) $enrollment->course_term_id
                    && (int) $invoice->member_id === (int) $enrollment->user_id
                    && (int) $invoice->booker_user_id === (int) $enrollment->booker_user_id;
            }
            if (! $matches) {
                throw ValidationException::withMessages(['invoice' => '帳單與預約會員或商品關聯不符，禁止記錄退款。']);
            }
            // Refund only what was actually collected: one successful positive
            // cash transaction equal to the invoice amount and no earlier refund.
            $successful = DB::table('payment_transactions')->where('invoice_id', $invoiceId)
                ->where('transaction_status', 1)->get(['transaction_amount']);
            $toCents = static fn ($amount): int => (int) round((float) $amount * 100);
            $collected = $successful->filter(fn ($t): bool => $toCents($t->transaction_amount) > 0);
            if ($collected->count() !== 1 || $successful->count() !== 1
                || $toCents($collected->first()->transaction_amount) !== $toCents($invoice->invoice_amount)) {
                throw ValidationException::withMessages(['invoice' => '查無與帳單金額相符的實收紀錄，禁止記錄退款。']);
            }
            DB::table('payment_transactions')->insert([
                'invoice_id' => $invoiceId,
                'transaction_code' => 'REFUND-'.Str::uuid(),
                'transaction_amount' => '-'.ltrim((string) $invoice->invoice_amount, '+-'),
                'currency' => $invoice->currency,
                'payment_method' => 0, 'transaction_status' => 1,
                'staff_id' => $admin->id, 'processed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoiceId)->update([
                'invoice_status' => 5, 'staff_id' => $admin->id, 'updated_at' => now(),
            ]);
            DB::table('invoice_refund_audits')->insert([
                'invoice_id' => $invoiceId, 'staff_id' => $admin->id,
                'reason' => $reason, 'created_at' => now(),
            ]);

            return ['id' => $invoiceId, 'status' => 5, 'replayed' => false];
        }, 3);
    }

    public function confirmCash(int $invoiceId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw new \App\Support\Api\ApiException(403, 'ADMIN_REQUIRED', '僅管理員可確認現金收款。');
        }

        return DB::transaction(function () use ($invoiceId, $admin): array {
            // Resolve parent first, then lock in term -> enrollment -> invoice order.
            // The hint is revalidated after all locks are acquired.
            $hint = DB::table('invoices')->where('id', $invoiceId)->first();
            if ($hint === null) {
                throw ValidationException::withMessages(['invoice' => '帳單不存在。']);
            }
            // Single-event payments follow the same lock order as expiry:
            // event -> booking group -> invoice. Never pay a stale booking.
            $lockedEventGroup = null;
            if ($hint->booking_type === '01') {
                $groupHint = EventBookingGroup::query()->find($hint->booking_id);
                if ($groupHint === null || CalendarEvent::query()
                    ->whereKey($groupHint->calendar_event_id)->lockForUpdate()->first() === null) {
                    throw ValidationException::withMessages(['invoice' => '預約或活動不存在。']);
                }
                $lockedEventGroup = EventBookingGroup::query()
                    ->whereKey($hint->booking_id)->lockForUpdate()->first();
                if ($lockedEventGroup === null
                    || (int) $lockedEventGroup->calendar_event_id !== (int) $groupHint->calendar_event_id) {
                    throw ValidationException::withMessages(['invoice' => '預約資料已變更。']);
                }
            }
            if ($hint->booking_type === '02') {
                $termId = DB::table('course_enrollments')->where('id', $hint->booking_id)
                    ->value('course_term_id');
                if ($termId === null || DB::table('course_terms')->where('id', $termId)
                    ->lockForUpdate()->first() === null) {
                    throw ValidationException::withMessages(['invoice' => '整期報名不存在。']);
                }
                $lockedEnrollment = DB::table('course_enrollments')
                    ->where('id', $hint->booking_id)->lockForUpdate()->first();
                if ($lockedEnrollment === null || (int) $lockedEnrollment->course_term_id !== (int) $termId) {
                    throw ValidationException::withMessages(['invoice' => '整期報名資料已變更。']);
                }
            }
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || $invoice->booking_type !== $hint->booking_type
                || (int) $invoice->booking_id !== (int) $hint->booking_id) {
                throw ValidationException::withMessages(['invoice' => '帳單資料已變更，請重新操作。']);
            }
            if ((int) $invoice->invoice_status === 1) {
                return ['id' => $invoiceId, 'status' => 1, 'replayed' => true];
            }
            if ((int) $invoice->invoice_status !== 0 || now()->gt($invoice->payment_due_at)) {
                throw ValidationException::withMessages(['invoice' => '帳單已取消、過期或不允許付款。']);
            }
            if ($invoice->booking_type === '02') {
                $enrollment = DB::table('course_enrollments')
                    ->where('id', $invoice->booking_id)->lockForUpdate()->first();
                if ($enrollment === null || $enrollment->status !== 'pending_payment') {
                    throw ValidationException::withMessages(['invoice' => '整期課程名額已失效，禁止收款。']);
                }
                // Recheck persisted member and delegation eligibility at the
                // moment cash is confirmed; earlier enrollment is not authority.
                $member = User::query()->find($enrollment->user_id);
                $booker = User::query()->find($enrollment->booker_user_id);
                if ($member === null || $booker === null
                    || ! app(BookingEngine::class)->canManageFamily($booker, $member, true)) {
                    throw ValidationException::withMessages([
                        'invoice' => '會員資格或家庭代訂權限已失效，禁止確認收款。',
                    ]);
                }

                // Validate the invoice's immutable business identity before cash settlement.
                if ((int) $invoice->product_id !== (int) $enrollment->course_term_id
                    || (int) $invoice->member_id !== (int) $enrollment->user_id
                    || (int) $invoice->booker_user_id !== (int) $enrollment->booker_user_id) {
                    throw ValidationException::withMessages([
                        'invoice' => '整期帳單與報名會員或課程關聯不符，禁止收款。',
                    ]);
                }

                // Paid bookings must never settle below the NT$1 minimum,
                // even if an invoice amount was corrupted after enrollment.
                if ((float) $invoice->invoice_amount < 1 || $invoice->currency !== 'TWD') {
                    throw ValidationException::withMessages([
                        'invoice' => '整期帳單金額不得低於 NT$1，且幣別必須為 TWD。',
                    ]);
                }

                // The enrollment transaction already creates every derived
                // booking; refuse cash if any required booking is missing.
                $sessionIds = DB::table('course_sessions')
                    ->where('course_term_id', $enrollment->course_term_id)
                    ->pluck('calendar_event_id');
                if ($sessionIds->isEmpty()) {
                    throw ValidationException::withMessages(['invoice' => '本期尚未安排課堂，禁止確認收款。']);
                }
                foreach ($sessionIds as $eventId) {
                    $requestId = (string) Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        'course-enrollment:'.$enrollment->id.':event:'.$eventId,
                    );
                    $group = EventBookingGroup::query()
                        ->where('client_request_id', $requestId)
                        ->where('calendar_event_id', $eventId)
                        ->where('status', EventBookingGroup::STATUS_CONFIRMED)
                        ->first();
                    $attendees = $group?->attendees()->where('active', true)->get();
                    if ($group === null || (int) $group->booker_user_id !== (int) $enrollment->booker_user_id
                        || (int) $group->seat_count !== 1 || $attendees->count() !== 1
                        || $attendees->first()->status !== 'confirmed'
                        || (int) $attendees->first()->member_user_id !== (int) $enrollment->user_id) {
                        throw ValidationException::withMessages([
                            'invoice' => '整期課堂預約或參加者關聯不符，禁止確認現金收款。',
                        ]);
                    }
                }
            }
            if ($invoice->booking_type === '01') {
                $group = $lockedEventGroup;
                if ($group === null || $group->status !== EventBookingGroup::STATUS_CONFIRMED
                    || (int) $group->booker_user_id !== (int) $invoice->booker_user_id
                    || (int) $group->calendar_event_id !== (int) $invoice->product_id) {
                    throw ValidationException::withMessages(['invoice' => '預約已失效或帳單關聯不符，禁止收款。']);
                }
                $attendeeIds = DB::table('event_booking_attendees')
                    ->where('event_booking_group_id', $group->id)
                    ->where('status', 'confirmed')->where('active', 1)
                    ->pluck('member_user_id');
                if ($attendeeIds->count() !== 1 || (int) $attendeeIds->first() !== (int) $invoice->member_id) {
                    throw ValidationException::withMessages(['invoice' => '預約參加者與帳單不符，禁止收款。']);
                }
                $booker = User::query()->find($group->booker_user_id);
                $member = User::query()->find($invoice->member_id);
                if ($booker === null || $member === null
                    || ! app(BookingEngine::class)->canManageFamily($booker, $member, true)) {
                    throw ValidationException::withMessages(['invoice' => '會員資格或家庭代訂權限已失效，禁止收款。']);
                }
            }
            DB::table('payment_transactions')->insert([
                'invoice_id' => $invoiceId,
                'transaction_code' => 'CASH-'.Str::uuid(),
                'transaction_amount' => $invoice->invoice_amount,
                'currency' => 'TWD',
                'payment_method' => 0,
                'transaction_status' => 1,
                'staff_id' => $admin->id,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoiceId)->update([
                'invoice_status' => 1, 'paid_at' => now(), 'staff_id' => $admin->id, 'updated_at' => now(),
            ]);
            if ($invoice->booking_type === '02') {
                DB::table('course_enrollments')->where('id', $invoice->booking_id)->update([
                    'status' => 'confirmed', 'fee_confirmed_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['id' => $invoiceId, 'status' => 1, 'replayed' => false];
        }, 3);
    }
}
