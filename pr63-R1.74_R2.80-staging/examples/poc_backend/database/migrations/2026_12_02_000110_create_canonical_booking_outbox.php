<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('canonical_booking_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_key', 160)->unique();
            $table->unsignedBigInteger('event_booking_id');
            $table->unsignedBigInteger('calendar_event_id');
            $table->string('transition', 32);
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at', 'id'], 'canonical_outbox_pending_idx');
            $table->index(['event_booking_id', 'id'], 'canonical_outbox_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_booking_outbox');
    }
};
