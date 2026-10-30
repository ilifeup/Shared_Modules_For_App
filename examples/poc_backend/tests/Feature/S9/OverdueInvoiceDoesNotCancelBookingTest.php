<?php

use App\Models\User;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Support\Facades\DB;

it('marks an overdue invoice without changing the existing course enrollment or releasing its seat', function () {
    $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    $termId = DB::table('course_terms')->insertGetId([
        'title' => 'Overdue preserves booking',
        'enrollment_capacity' => 1,
        'waitlist_capacity' => 5,
        'price_amount' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $enrollmentId = DB::table('course_enrollments')->insertGetId([
        'course_term_id' => $termId,
        'user_id' => $member->id,
        'booker_user_id' => $member->id,
        'status' => 'pending_payment',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_code' => 'TEST-OVERDUE-'.uniqid(),
        'invoice_status' => 0,
        'invoice_amount' => 100,
        'currency' => 'TWD',
        'member_id' => $member->id,
        'booker_user_id' => $member->id,
        'transaction_type' => '02',
        'product_id' => $termId,
        'booking_type' => '02',
        'booking_id' => $enrollmentId,
        'app_stage' => 0,
        'payment_method' => 0,
        'invoice_datetime' => now()->subDays(4),
        'payment_due_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(CashInvoiceService::class)->expireDue())->toBe(1);
    expect(app(CashInvoiceService::class)->expireDue())->toBe(0);

    $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
    expect((int) $invoice->invoice_status)->toBe(0)
        ->and($invoice->overdue_at)->not->toBeNull()
        ->and(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))
        ->toBe('pending_payment');
});
