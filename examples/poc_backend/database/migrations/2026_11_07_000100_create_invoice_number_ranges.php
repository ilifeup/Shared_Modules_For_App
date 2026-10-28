<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_ranges', function (Blueprint $table) {
            $table->id();
            $table->char('prefix', 2);
            $table->unsignedInteger('start_number');
            $table->unsignedInteger('end_number');
            $table->unsignedInteger('next_number');
            $table->string('period_code', 20);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['active', 'period_code']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->index('invoice_no');
            $table->foreignId('invoice_number_range_id')->nullable()->constrained('invoice_number_ranges')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['invoice_number_range_id']);
            $table->dropColumn('invoice_number_range_id');
            $table->dropIndex(['invoice_no']);
        });
        Schema::dropIfExists('invoice_number_ranges');
    }
};
