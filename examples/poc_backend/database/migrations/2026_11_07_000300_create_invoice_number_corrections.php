<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('old_number', 10);
            $table->string('new_number', 10);
            $table->string('reason', 1000);
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('invoice_number_corrections');
    }
};
