<?php

use App\Models\User;
use App\Services\Payment\CashInvoiceService;
use App\Support\Api\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

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

    // An unpaid invoice whose deadline was moved forward must not be marked overdue.
    $futureInvoiceId = DB::table('invoices')->insertGetId([
        'invoice_code' => 'TEST-FUTURE-'.uniqid(),
        'invoice_status' => 0,
        'invoice_amount' => 100,
        'currency' => 'TWD',
        'member_id' => $member->id,
        'booker_user_id' => $member->id,
        'transaction_type' => '02',
        'product_id' => $termId,
        'booking_type' => '02',
        'booking_id' => $enrollmentId + 10000,
        'app_stage' => 0,
        'payment_method' => 0,
        'invoice_datetime' => now(),
        'payment_due_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(CashInvoiceService::class)->expireDue())->toBe(1);
    expect(app(CashInvoiceService::class)->expireDue())->toBe(0);
    expect(DB::table('invoices')->where('id', $futureInvoiceId)->value('overdue_at'))->toBeNull();

    $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
    expect((int) $invoice->invoice_status)->toBe(0)
        ->and($invoice->overdue_at)->not->toBeNull()
        ->and(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))
        ->toBe('pending_payment');
});

it('allows an administrator to extend an active unpaid deadline and records an audit event', function () {
    Role::findOrCreate(User::ADMIN_ROLE, 'web');
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

    $nonAdmin = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    expect(fn () => app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $nonAdmin, $newDeadline->toIso8601String(), 'unauthorized extension'
    ))->toThrow(ApiException::class);
    expect(Carbon::parse(DB::table('invoices')->where('id', $invoiceId)->value('payment_due_at'))->timestamp)
        ->toBe($original->timestamp);

    expect(fn () => app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, $newDeadline->toIso8601String(), '  '
    ))->toThrow(ValidationException::class);
    expect(fn () => app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, $original->toIso8601String(), 'same deadline'
    ))->toThrow(ValidationException::class);
    expect(Carbon::parse(DB::table('invoices')->where('id', $invoiceId)->value('payment_due_at'))->timestamp)
        ->toBe($original->timestamp);
    expect(DB::table('audit_events')->where('event', 'invoice.payment_deadline_extended')->count())->toBe(0);

    app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, $newDeadline->toIso8601String(), 'member requested more time'
    );

    expect(Carbon::parse(DB::table('invoices')->where('id', $invoiceId)->value('payment_due_at'))->timestamp)
        ->toBe($newDeadline->timestamp)
        ->and(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))
        ->toBe('pending_payment')
        ->and(DB::table('audit_events')->where('event', 'invoice.payment_deadline_extended')->count())
        ->toBe(1);
});

it('does not extend an already overdue invoice or revive a cancelled enrollment', function () {
    Role::findOrCreate(User::ADMIN_ROLE, 'web');
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
    ))->toThrow(ValidationException::class);
    expect(DB::table('course_enrollments')->where('id', $enrollmentId)->value('status'))->toBe('cancelled');

    // A cancelled booking is never resurrected even if the due date is still in the future.
    DB::table('course_enrollments')->where('id', $enrollmentId)->update(['status' => 'pending_payment']);
    DB::table('invoices')->where('id', $invoiceId)->update([
        'payment_due_at' => now()->subMinute(),
        'overdue_at' => now(),
    ]);
    expect(fn () => app(CashInvoiceService::class)->extendPaymentDeadline(
        $invoiceId, $admin, now()->addDay()->toIso8601String(), 'attempt to extend overdue'
    ))->toThrow(ValidationException::class);
    expect(DB::table('invoices')->where('id', $invoiceId)->value('overdue_at'))->not->toBeNull();
});

it('keeps an event booking and FIFO waitlist unchanged through legacy cash expiry retries', function () {
    $event = s9Event(['booking_capacity' => 1, 'waitlist_capacity' => 2]);
    $booker = User::factory()->create(['verified_status' => 2]);
    $waiting = User::factory()->create(['verified_status' => 2]);

    $groupId = groupBook($event, $booker)->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')->json('data.id');
    groupBook($event, $waiting)->assertCreated()
        ->assertJsonPath('data.status', 'waitlisted');

    $before = DB::table('event_waitlist_entries')
        ->where('calendar_event_id', $event->id)
        ->orderBy('id')->get(['id', 'status', 'sequence'])->toArray();

    $invoiceId = DB::table('invoices')->insertGetId([
        'invoice_code' => 'TEST-EVENT-OVERDUE-'.uniqid(),
        'invoice_status' => 0,
        'invoice_amount' => 100,
        'currency' => 'TWD',
        'member_id' => $booker->id,
        'booker_user_id' => $booker->id,
        'transaction_type' => '01',
        'product_id' => $event->id,
        'booking_type' => '01',
        'booking_id' => $groupId,
        'app_stage' => 0,
        'payment_method' => 0,
        'invoice_datetime' => now()->subDays(2),
        'payment_due_at' => now()->subDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $engine = app(\\App\\Services\\Booking\\BookingEngine::class);
    expect($engine->expireCashHold($groupId, $invoiceId))->toBeTrue();
    expect($engine->expireCashHold($groupId, $invoiceId))->toBeFalse();

    $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
    expect((int) $invoice->invoice_status)->toBe(0)
        ->and($invoice->overdue_at)->not->toBeNull()
        ->and(DB::table('event_booking_groups')->where('id', $groupId)->value('status'))
        ->toBe('confirmed')
        ->and(DB::table('event_waitlist_entries')->where('calendar_event_id', $event->id)
            ->orderBy('id')->get(['id', 'status', 'sequence'])->toArray())
        ->toEqual($before);
});
