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

it('allows an administrator to extend an active unpaid deadline and records an audit event', function () {
    \Spatie\Permission\Models\Role::findOrCreate(User::ADMIN_ROLE, 'web');
    $admin = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    $admin->assignRole(User::ADMIN_ROLE);
    $termId = DB::table('course_terms')->insertGetId([
        'title' => 'Extend active course invoice', 'enrollment_capacity' => 1,
        'waitlist_capacity' => 5, 'price_amount' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $enrollmentId = DB::table('course_enrollments')->insertGetId([
        'course_term_id' => $termId, 'user_id' => $admin->id,
        'booker_user_id' => $admin->id, 'status' => 'pending_payment',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $original = now()->addHours(2)->startOfMinute();
    $newDeadline = now()->addHours(8)->startOfMinute();
    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_code' => 'TEST-EXTEND-'.uniqid(), 'invoice_status' => 0,
        'invoice_amount' => 100, 'currency' => 'TWD', 'member_id' => $admin->id,
        'booker_user_id' => $admin->id, 'transaction_type' => '02',
        'product_id' => $termId, 'booking_type' => '02', 'booking_id' => $enrollmentId,
        'app_stage' => 0, 'payment_method' => 0, 'invoice_datetime' => now(),
        'payment_due_at' => $original, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, $newDeadline->toIso8601String(), 'member requested more time'
    );

    expect(\Illuminate\Support\Carbon::parse(DB::table('invoices')->where('id', $invoiceId)->value('payment_due_at'))->timestamp)
        ->toBe($newDeadline->timestamp)
        ->and(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))
        ->toBe('pending_payment')
        ->and(DB::table('audit_events')->where('event', 'invoice.payment_deadline_extended')->count())
        ->toBe(1);
});

it('does not extend an already overdue invoice or revive a cancelled enrollment', function () {
    \Spatie\Permission\Models\Role::findOrCreate(User::ADMIN_ROLE, 'web');
    $admin = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    $admin->assignRole(User::ADMIN_ROLE);
    $termId = DB::table('course_terms')->insertGetId([
        'title' => 'Cancelled enrollment cannot extend', 'enrollment_capacity' => 1,
        'waitlist_capacity' => 5, 'price_amount' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $enrollmentId = DB::table('course_enrollments')->insertGetId([
        'course_term_id' => $termId, 'user_id' => $admin->id,
        'booker_user_id' => $admin->id, 'status' => 'cancelled',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_code' => 'TEST-CANCELLED-'.uniqid(), 'invoice_status' => 0,
        'invoice_amount' => 100, 'currency' => 'TWD', 'member_id' => $admin->id,
        'booker_user_id' => $admin->id, 'transaction_type' => '02',
        'product_id' => $termId, 'booking_type' => '02', 'booking_id' => $enrollmentId,
        'app_stage' => 0, 'payment_method' => 0, 'invoice_datetime' => now(),
        'payment_due_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, now()->addDay()->toIso8601String(), 'attempt to revive'
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))->toBe('cancelled');
});
