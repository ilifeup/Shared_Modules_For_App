<?php

namespace App\Services\Payment;

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
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

            return ['id' => $invoiceId, 'status' => 1, 'replayed' => false];
        }, 3);
    }
}
