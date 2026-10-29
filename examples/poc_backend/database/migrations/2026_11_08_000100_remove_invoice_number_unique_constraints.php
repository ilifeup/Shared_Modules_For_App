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
        foreach (['invoices' => 'invoices_invoice_no_unique',
            'invoice_number_corrections' => 'invoice_number_corrections_old_number_unique'] as $tableName => $indexName) {
            $indexes = Schema::getIndexes($tableName);
            if (collect($indexes)->contains(fn (array $index): bool => $index['name'] === $indexName && $index['unique'])) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropUnique($indexName);
                });
            }
        }
    }

    public function down(): void
    {
        // Restoring uniqueness could fail on valid duplicated numbers.
        // Do not recreate a constraint that contradicts the MVP policy.
    }
};
