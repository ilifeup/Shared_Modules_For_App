<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null means inherit the administrator's global default (38 hours).
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unsignedInteger('payment_hold_hours')->nullable();
            $table->decimal('price_amount', 12, 2)->default(0);
        });

        Schema::table('course_terms', function (Blueprint $table) {
            $table->unsignedInteger('payment_hold_hours')->nullable();
            $table->decimal('price_amount', 12, 2)->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_code', 40)->unique();
            $table->string('invoice_no', 30)->nullable();
            $table->unsignedTinyInteger('invoice_status')->default(0);
            $table->decimal('invoice_amount', 12, 2);
            $table->char('currency', 3)->default('TWD');
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('booker_user_id')->constrained('users')->restrictOnDelete();
            $table->char('transaction_type', 2);
            $table->unsignedBigInteger('product_id');
            $table->char('booking_type', 2);
            $table->unsignedBigInteger('booking_id');
            $table->unsignedTinyInteger('app_stage')->default(0);
            $table->unsignedTinyInteger('payment_method')->default(0);
            $table->timestamp('invoice_datetime');
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('tax_invoice_issued_at')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // booking_type + booking_id identify an actual reservation, not a product.
            $table->unique(['booking_type', 'booking_id'], 'invoices_booking_unique');
            $table->index(['invoice_status', 'payment_due_at']);
            $table->index(['member_id', 'created_at']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('transaction_code', 50)->unique();
            $table->decimal('transaction_amount', 12, 2);
            $table->char('currency', 3)->default('TWD');
            $table->unsignedTinyInteger('payment_method')->default(0);
            $table->unsignedTinyInteger('transaction_status')->default(0);
            $table->string('provider_code', 40)->nullable();
            $table->string('external_transaction_id', 120)->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('staff_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_code', 'external_transaction_id'], 'payment_provider_external_unique');
            $table->index(['invoice_id', 'transaction_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('invoices');

        Schema::table('course_terms', function (Blueprint $table) {
            $table->dropColumn(['payment_hold_hours', 'price_amount']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['payment_hold_hours', 'price_amount']);
        });
    }
};
