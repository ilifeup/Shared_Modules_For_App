<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualRefundCancellationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(User::ADMIN_ROLE);

        return $admin;
    }

    /** A paid invoice with its one real cash collection, as confirmCash() leaves it. */
    private function invoice(string $type, int $bookingId, int $memberId, int $productId): int
    {
        $id = DB::table('invoices')->insertGetId([
            'invoice_code' => 'TEST-'.uniqid(),
            'invoice_status' => 1,
            'invoice_amount' => '100.00',
            'currency' => 'TWD',
            'member_id' => $memberId,
            'booker_user_id' => $memberId,
            'transaction_type' => $type,
            'product_id' => $productId,
            'booking_type' => $type,
            'booking_id' => $bookingId,
            'invoice_datetime' => now(),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_transactions')->insert([
            'invoice_id' => $id,
            'transaction_code' => 'CASH-'.Str::uuid(),
            'transaction_amount' => '100.00',
            'currency' => 'TWD',
            'payment_method' => 0,
            'transaction_status' => 1,
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function refunds(int $invoice): int
    {
        return DB::table('payment_transactions')->where('invoice_id', $invoice)
            ->where('transaction_amount', '<', 0)->count();
    }

    public function test_refund_requires_cancelled_course_enrollment_and_is_atomic(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $term = DB::table('course_terms')->insertGetId([
            'title' => 'Refund term',
            'enrollment_capacity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $enrollment = DB::table('course_enrollments')->insertGetId([
            'course_term_id' => $term,
            'user_id' => $member->id,
            'booker_user_id' => $member->id,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = $this->invoice('02', $enrollment, $member->id, $term);

        try {
            app(CashInvoiceService::class)->recordManualRefund($invoice, $admin, 'Manual refund');
            $this->fail('Active course enrollment must not be refunded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertSame(1, (int) DB::table('invoices')->where('id', $invoice)->value('invoice_status'));
        $this->assertSame(0, $this->refunds($invoice));
        $this->assertSame(0, DB::table('invoice_refund_audits')->count());

        DB::table('course_enrollments')->where('id', $enrollment)->update(['status' => 'cancelled']);
        $result = app(CashInvoiceService::class)->recordManualRefund($invoice, $admin, 'Manual refund');
        $this->assertSame(5, $result['status']);
        // Back-office refund records money movement only; the API owns cancellation.
        $this->assertSame('cancelled', DB::table('course_enrollments')->where('id', $enrollment)->value('status'));
        $this->assertSame(1, $this->refunds($invoice));
        $this->assertSame(1, DB::table('invoice_refund_audits')->where('invoice_id', $invoice)->count());

        // A retried refund replays the recorded result and never refunds twice.
        $replay = app(CashInvoiceService::class)->recordManualRefund($invoice, $admin, 'Duplicate');
        $this->assertSame(['id' => $invoice, 'status' => 5, 'replayed' => true], $replay);
        $this->assertSame(1, $this->refunds($invoice));
        $this->assertSame(1, DB::table('invoice_refund_audits')->where('invoice_id', $invoice)->count());
    }

    public function test_refund_requires_cancelled_event_booking(): void
    {
        $admin = $this->admin();
        $member = User::factory()->create();
        $event = s9Event(['booking_capacity' => 2]);
        $group = DB::table('event_booking_groups')->insertGetId([
            'calendar_event_id' => $event->id,
            'booker_user_id' => $member->id,
            'contact_name' => $member->name,
            'status' => 'confirmed',
            'seat_count' => 1,
            'client_request_id' => (string) Str::uuid(),
            'booked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('event_booking_attendees')->insert([
            'event_booking_group_id' => $group,
            'calendar_event_id' => $event->id,
            'member_user_id' => $member->id,
            'name' => $member->name,
            'status' => 'confirmed',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invoice = $this->invoice('01', $group, $member->id, $event->id);

        try {
            app(CashInvoiceService::class)->recordManualRefund($invoice, $admin, 'Before cancellation');
            $this->fail('Active event booking must not be refunded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertSame(0, $this->refunds($invoice));
        $this->assertSame(0, DB::table('invoice_refund_audits')->count());

        DB::table('event_booking_groups')->where('id', $group)->update(['status' => 'cancelled']);
        $result = app(CashInvoiceService::class)->recordManualRefund($invoice, $admin, 'After cancellation');
        $this->assertSame(5, $result['status']);
        $this->assertSame('cancelled', DB::table('event_booking_groups')->where('id', $group)->value('status'));
        $this->assertSame(1, $this->refunds($invoice));
        $this->assertSame(1, DB::table('invoice_refund_audits')->where('invoice_id', $invoice)->count());
    }
}
