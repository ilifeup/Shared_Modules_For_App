<?php

namespace App\Services\Payment;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceNumberAllocator
{
    public function addRange(string $start, string $end, string $period, User $admin): int
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可設定發票字軌。']);
        }
        $start = strtoupper(trim($start));
        $end = strtoupper(trim($end));
        if (! preg_match('/^[A-Z]{2}[0-9]{8}$/', $start)
            || ! preg_match('/^[A-Z]{2}[0-9]{8}$/', $end)
            || substr($start, 0, 2) !== substr($end, 0, 2)
            || (int) substr($start, 2) > (int) substr($end, 2)
            || ! preg_match('/^[0-9]{4}(0[1-9]|1[0-2])$/', $period)) {
            throw ValidationException::withMessages(['range' => '須輸入相同兩碼字軌、八碼數字及合法期別（YYYYMM）。']);
        }

        return DB::transaction(function () use ($start, $end, $period, $admin): int {
            // Lock the range set by serializing creation against a stable user row.
            DB::table('users')->orderBy('id')->lockForUpdate()->first();
            $prefix = substr($start, 0, 2);
            $from = (int) substr($start, 2);
            $to = (int) substr($end, 2);
            if (DB::table('invoice_number_ranges')->where('prefix', $prefix)
                ->where('start_number', '<=', $to)->where('end_number', '>=', $from)->exists()) {
                throw ValidationException::withMessages(['range' => '發票區間與既有字軌重疊。']);
            }

            return DB::table('invoice_number_ranges')->insertGetId([
                'prefix' => $prefix, 'start_number' => $from, 'end_number' => $to,
                'next_number' => $from, 'period_code' => $period, 'active' => true,
                'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    /** Correct a previously assigned number; retain both numbers in the audit ledger. */
    public function correct(int $invoiceId, string $newNumber, string $reason, User $admin): string
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可更正發票號碼。']);
        }
        $newNumber = strtoupper(trim($newNumber));
        if (! preg_match('/^[A-Z]{2}[0-9]{8}$/', $newNumber)) {
            throw ValidationException::withMessages(['invoice_no' => '發票號碼須為兩碼英文字母與八碼數字。']);
        }

        return DB::transaction(function () use ($invoiceId, $newNumber, $reason, $admin): string {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || $invoice->invoice_no === null) {
                throw ValidationException::withMessages(['invoice' => '此帳單尚未配發發票號碼。']);
            }
            if ($invoice->invoice_no === $newNumber) {
                return $newNumber;
            }
            DB::table('invoice_number_corrections')->insert([
                'invoice_id' => $invoiceId,
                'old_number' => $invoice->invoice_no,
                'new_number' => $newNumber,
                'reason' => trim($reason),
                'staff_id' => $admin->id,
                'created_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoiceId)->update([
                'invoice_no' => $newNumber,
                'invoice_number_range_id' => null,
                'updated_at' => now(),
            ]);

            return $newNumber;
        }, 3);
    }

    public function allocate(int $invoiceId, User $admin): string
    {
        if (! $admin->is_active || ! $admin->isAdmin()) {
            throw ValidationException::withMessages(['admin' => '僅管理員可配發發票號碼。']);
        }

        return DB::transaction(function () use ($invoiceId): string {
            $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || (int) $invoice->invoice_status !== 1) {
                throw ValidationException::withMessages(['invoice' => '僅已收款帳單可配發發票號碼。']);
            }
            if ($invoice->invoice_no !== null) {
                return $invoice->invoice_no;
            }
            // A shared lock serializes allocation across different invoice rows.
            DB::table('users')->orderBy('id')->lockForUpdate()->first();
            $range = DB::table('invoice_number_ranges')->where('active', true)
                ->where('period_code', now()->format('Ym'))
                ->whereColumn('next_number', '<=', 'end_number')
                ->orderBy('id')->lockForUpdate()->first();
            if ($range === null) {
                throw ValidationException::withMessages(['range' => '目前期別沒有可用發票號碼，請管理員先設定字軌。']);
            }
            $number = $range->prefix.str_pad((string) $range->next_number, 8, '0', STR_PAD_LEFT);
            DB::table('invoice_number_ranges')->where('id', $range->id)->update([
                'next_number' => $range->next_number + 1, 'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoiceId)->update([
                'invoice_no' => $number, 'invoice_number_range_id' => $range->id,
                'tax_invoice_issued_at' => now(), 'updated_at' => now(),
            ]);

            return $number;
        }, 3);
    }
}
