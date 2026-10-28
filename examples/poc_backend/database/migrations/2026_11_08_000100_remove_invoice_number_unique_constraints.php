<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may have run the original unique-index migrations.
        // Invoice numbers are editable and intentionally not unique.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_invoice_no_unique');
        });
        Schema::table('invoice_number_corrections', function (Blueprint $table) {
            $table->dropUnique('invoice_number_corrections_old_number_unique');
        });
    }

    public function down(): void
    {
        // Restoring uniqueness could fail on valid duplicated numbers.
        // Do not recreate a constraint that contradicts the MVP policy.
    }
};
