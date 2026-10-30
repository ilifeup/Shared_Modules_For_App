<?php

namespace App\Services\Payment;

use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft canonical invoice adapter. Must be invoked inside the event-locked
 * reservation transaction, not as an asynchronous after-commit action.
 * The booking_type discriminator '03' is RESERVED FOR REVIEW before cutover;
 * every invoice consumer (refund, reconciliation, exports) must recognize it.
 */
final class CanonicalEventInvoiceService
{
    public const BOOKING_TYPE = '03';

    public function createForConfirmedBooking(CalendarEvent $event, EventBooking $booking): void
    {
        if ((float) $event->price_amount <= 0) {
            return;
        }
        if (! DB::transactionLevel()) {
            throw new \LogicException('Canonical invoice must be created inside a booking transaction.');
        }
        // Lock and reload the seat owner; callers may hold a stale model after
        // a cancellation or reassignment. The event lock is owned by caller.
        $booking = EventBooking::query()->whereKey($booking->id)->lockForUpdate()->first();
        if ($booking === null || (int) $booking->calendar_event_id !== (int) $event->id
            || ! $booking->isActive()
            || $booking->status !== EventBooking::STATUS_CONFIRMED
            || ! $booking->member_user_id || ! $booking->booker_user_id) {
            throw new ApiException(409, 'INVALID_CANONICAL_INVOICE_BOOKING', '帳單只能對應有效的已確認單人預約。');
        }
        $invoices = DB::table('invoices')
            ->where('booking_type', self::BOOKING_TYPE)
            ->where('booking_id', $booking->id)->lockForUpdate()->limit(2)->get();
        if ($invoices->count() > 1) {
            throw new ApiException(409, 'CANONICAL_INVOICE_DUPLICATE',
                '同一正式預約存在多筆帳單，必須先由管理員核對。');
        }
        $existing = $invoices->first();
        if ($existing !== null) {
            if ((string) $existing->transaction_type !== '01'
                || (string) $existing->booking_type !== self::BOOKING_TYPE
                || (string) $existing->currency !== 'TWD'
                || (int) $existing->product_id !== (int) $event->id
                || (int) $existing->member_id !== (int) $booking->member_user_id
                || (int) $existing->booker_user_id !== (int) $booking->booker_user_id
                || bccomp((string) $existing->invoice_amount, (string) $event->price_amount, 2) !== 0) {
                throw new ApiException(409, 'CANONICAL_INVOICE_CONFLICT', '既有帳單與預約身分或金額不一致。');
            }
            return;
        }

        // Only a NEW invoice needs a future deadline. Idempotent replays
        // must not fail merely because the event has since started.
        if (! $event->starts_at->isFuture()) {
            throw ValidationException::withMessages(['payment' => '活動已開始，無法建立付款帳單。']);
        }
        $hours = $event->payment_hold_hours ?? app(Settings::class)->int('payment_hold_hours');
        $deadline = now()->addHours($hours)->min($event->starts_at);

        app(CashInvoiceService::class)->create(
            '01', (int) $event->id, self::BOOKING_TYPE, (int) $booking->id,
            (int) $booking->member_user_id, (int) $booking->booker_user_id,
            (string) $event->price_amount, (int) $hours, $deadline
        );
    }
}
