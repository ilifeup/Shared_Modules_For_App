<?php

namespace App\Services\Payment;

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'invoice_code' => 'TMP-'.\Illuminate\Support\Str::uuid(),
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
                $invoice = DB::table('invoices')->where('id', $item->id)->lockForUpdate()->first();
                if ($invoice === null || (int) $invoice->invoice_status !== 0
                    || $invoice->payment_due_at === null || now()->lt($invoice->payment_due_at)) {
                    return false;
                }
                $termId = DB::table('course_enrollments')->where('id', $invoice->booking_id)
                    ->value('course_term_id');
                if ($termId !== null) {
                    DB::table('course_terms')->where('id', $termId)->lockForUpdate()->first();
                }
                $entry = DB::table('course_enrollments')->where('id', $invoice->booking_id)
                    ->lockForUpdate()->first();
                if ($entry === null || $entry->status !== 'pending_payment') {
                    return false;
                }
                DB::table('course_enrollments')->where('id', $entry->id)
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('invoices')->where('id', $invoice->id)
                    ->update(['invoice_status' => 3, 'updated_at' => now()]);
                $this->releaseCourseBookings($entry);
                $this->promoteCourseWaitlist((int) $entry->course_term_id);
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
    private function releaseCourseBookings(object $entry): void
    {
        $actor = User::query()->findOrFail($entry->booker_user_id);
        $events = DB::table('course_sessions')
            ->where('course_term_id', $entry->course_term_id)
            ->orderBy('calendar_event_id')->pluck('calendar_event_id');

        foreach ($events as $eventId) {
            $requestId = (string) \Ramsey\Uuid\Uuid::uuid5(
                \Ramsey\Uuid\Uuid::NAMESPACE_URL,
                'course-enrollment:'.$entry->id.':event:'.$eventId,
            );
            $group = EventBookingGroup::query()
                ->where('client_request_id', $requestId)
                ->where('status', EventBookingGroup::STATUS_CONFIRMED)->first();
            if ($group !== null) {
                app(BookingEngine::class)->cancelGroup($group, $actor, true, 'course_payment_expired');
            }
        }
    }

    /**
     * Promote only the first waiting course member; never bypass an ineligible head.
     * Caller must hold the course-term lock.
     */
    private function promoteCourseWaitlist(int $termId): void
    {
        $term = DB::table('course_terms')->where('id', $termId)->first();
        if ($term === null) {
            return;
        }
        $occupied = DB::table('course_enrollments')->where('course_term_id', $termId)
            ->whereIn('status', ['pending_payment', 'confirmed'])->count();
        if ((float) ($term->price_amount ?? 0) < 1 || $occupied >= (int) $term->enrollment_capacity) {
            return;
        }
        $head = DB::table('course_enrollments')->where('course_term_id', $termId)
            ->where('status', 'waitlisted')->orderBy('waitlist_sequence')->orderBy('id')
            ->lockForUpdate()->first();
        if ($head === null) {
            return;
        }
        $hours = (int) ($term->payment_hold_hours ?? app(Settings::class)->int('payment_hold_hours'));
        $firstStart = DB::table('course_sessions')
            ->join('calendar_events', 'calendar_events.id', '=', 'course_sessions.calendar_event_id')
            ->where('course_sessions.course_term_id', $termId)
            ->min('calendar_events.starts_at');
        if ($firstStart === null || now()->copy()->addHours($hours)->gt($firstStart)) {
            return;
        }
        $member = User::query()->find($head->user_id);
        $booker = User::query()->find($head->booker_user_id);
        if ($member === null || $booker === null || ! $member->is_active
            || ! $member->isVerifiedMember()
            || ! app(BookingEngine::class)->canManageFamily($booker, $member, true)) {
            return;
        }
        // The promoted member must receive all session seats before an invoice.
        // If any occurrence is unavailable, keep FIFO head on the waitlist.
        $engine = app(BookingEngine::class);
        $eventIds = DB::table('course_sessions')
            ->where('course_term_id', $termId)->orderBy('calendar_event_id')
            ->pluck('calendar_event_id');
        if ($eventIds->isEmpty()) {
            return;
        }
        foreach ($eventIds as $eventId) {
            $event = CalendarEvent::query()->findOrFail($eventId);
            if ($engine->unavailableReason($event, 1, true) !== null) {
                return;
            }
        }
        foreach ($eventIds as $eventId) {
            $event = CalendarEvent::query()->findOrFail($eventId);
            $group = $engine->book($event, new \App\Services\Booking\BookingRequest(
                booker: $booker,
                contactName: $booker->name,
                contactEmail: $booker->email,
                contactPhone: $booker->phone_e164,
                attendees: [['name' => $member->name, 'member_user_id' => $member->id]],
                clientRequestId: (string) \Ramsey\Uuid\Uuid::uuid5(
                    \Ramsey\Uuid\Uuid::NAMESPACE_URL,
                    'course-enrollment:'.$head->id.':event:'.$eventId,
                ),
                byAdmin: true,
            ), coveredByCourseTerm: true);
            if ($group->status !== EventBookingGroup::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['session' => '候補遞補課堂預約失敗，請聯絡管理員。']);
            }
        }
        DB::table('course_enrollments')->where('id', $head->id)->update([
            'status' => 'pending_payment', 'waitlist_sequence' => null, 'updated_at' => now(),
        ]);
        $this->create('02', $termId, '02', (int) $head->id,
            (int) $head->user_id, (int) $head->booker_user_id,
            (string) $term->price_amount, $hours);
    }

    /**
     * Manual cash refund only. Does not reassign an issued invoice number.
     * The operator must complete the real-world refund before recording it here.
     */
    public function recordManualRefund(int $invoiceId, User $admin, string $reason): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可記錄退款。']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => '請填寫退款原因。']);
        }
        return DB::transaction(function () use ($invoiceId, $admin, $reason): array {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || (int) $invoice->invoice_status !== 1) {
                throw ValidationException::withMessages(['invoice' => '僅已付款且尚未退款的帳單可以退款。']);
            }
            DB::table('payment_transactions')->insert([
                'invoice_id' => $invoiceId,
                'transaction_code' => 'REFUND-'.\Illuminate\Support\Str::uuid(),
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
            return ['id' => $invoiceId, 'status' => 5];
        }, 3);
    }

    public function confirmCash(int $invoiceId, User $admin): array
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可確認現金收款。']);
        }

        return DB::transaction(function () use ($invoiceId, $admin): array {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null) {
                throw ValidationException::withMessages(['invoice' => '帳單不存在。']);
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
                // Do not accept cash before every course session has an active
                // booking for this exact enrollment. The existing enrollment
                // flow does not yet create these bookings; fail closed until
                // the atomic reservation migration is completed.
                $sessionIds = DB::table('course_sessions')
                    ->where('course_term_id', $enrollment->course_term_id)
                    ->pluck('calendar_event_id');
                if ($sessionIds->isEmpty()) {
                    throw ValidationException::withMessages(['invoice' => '本期尚未安排課堂，禁止確認收款。']);
                }
                foreach ($sessionIds as $eventId) {
                    $requestId = (string) \Ramsey\Uuid\Uuid::uuid5(
                        \Ramsey\Uuid\Uuid::NAMESPACE_URL,
                        'course-enrollment:'.$enrollment->id.':event:'.$eventId,
                    );
                    $booked = EventBookingGroup::query()
                        ->where('client_request_id', $requestId)
                        ->where('calendar_event_id', $eventId)
                        ->where('status', EventBookingGroup::STATUS_CONFIRMED)
                        ->exists();
                    if (! $booked) {
                        throw ValidationException::withMessages([
                            'invoice' => '整期課堂預約尚未全部建立，禁止確認現金收款。',
                        ]);
                    }
                }
            }
            if ($invoice->booking_type === '01') {
                $group = EventBookingGroup::query()->whereKey($invoice->booking_id)->first();
                if ($group === null || $group->status !== EventBookingGroup::STATUS_CONFIRMED) {
                    throw ValidationException::withMessages(['invoice' => '預約已失效，禁止收款。']);
                }
            }
            DB::table('payment_transactions')->insert([
                'invoice_id' => $invoiceId,
                'transaction_code' => 'CASH-'.\Illuminate\Support\Str::uuid(),
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
