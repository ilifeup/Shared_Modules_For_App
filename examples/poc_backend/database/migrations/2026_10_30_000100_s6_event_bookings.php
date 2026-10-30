<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S6 M08 bookings for calendar events. Cancelled bookings are kept.
 * `active` is 1 for a confirmed booking and NULL once cancelled, so the unique
 * index allows one active booking per member and event (NULLs do not collide)
 * while keeping any number of cancelled ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->boolean('booking_enabled')->default(false)->after('audience');
            $table->unsignedInteger('booking_capacity')->nullable()->after('booking_enabled');
            $table->timestamp('booking_opens_at')->nullable()->after('booking_capacity');
            // Null = bookings close when the event starts.
            $table->timestamp('booking_closes_at')->nullable()->after('booking_opens_at');
        });

        Schema::create('event_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Temporary S6 API compatibility.
            $table->foreignId('member_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('booker_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('client_request_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('waitlist_sequence')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('status', 20)->default('confirmed'); // confirmed | cancelled
            $table->unsignedTinyInteger('active')->nullable()->default(1);
            $table->timestamp('booked_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['calendar_event_id', 'user_id', 'active']);
            $table->unique(['calendar_event_id', 'member_user_id', 'active'], 'event_bookings_member_active_unique');
            $table->unique('client_request_id', 'event_bookings_request_unique');
            $table->index(['booker_user_id', 'booked_at'], 'event_bookings_booker_history_index');
            $table->index(['calendar_event_id', 'status', 'waitlist_sequence'], 'event_bookings_waitlist_order_index');
            $table->index(['calendar_event_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_bookings');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['booking_enabled', 'booking_capacity', 'booking_opens_at', 'booking_closes_at']);
        });
    }
};
