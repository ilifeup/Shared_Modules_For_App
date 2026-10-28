<?php

namespace Tests\Feature\S9;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_first_invoice_and_transaction_schema_exists(): void
    {
        foreach ([
            'id', 'invoice_code', 'invoice_no', 'invoice_status',
            'invoice_amount', 'currency', 'member_id', 'booker_user_id',
            'transaction_type', 'product_id', 'booking_type', 'booking_id',
            'app_stage', 'payment_method', 'invoice_datetime', 'payment_due_at',
            'paid_at', 'tax_invoice_issued_at', 'staff_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('invoices', $column), $column);
        }

        foreach ([
            'id', 'invoice_id', 'transaction_code', 'transaction_amount',
            'currency', 'payment_method', 'transaction_status', 'provider_code',
            'external_transaction_id', 'idempotency_key', 'staff_id', 'processed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('payment_transactions', $column), $column);
        }

        foreach (['payment_hold_hours', 'price_amount'] as $column) {
            $this->assertTrue(Schema::hasColumn('calendar_events', $column), $column);
        }

        foreach (['payment_hold_hours', 'booking_closes_at', 'price_amount'] as $column) {
            $this->assertTrue(Schema::hasColumn('course_terms', $column), $column);
        }
    }
}
