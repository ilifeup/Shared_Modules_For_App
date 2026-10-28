<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A category is a label; a group is one shared capacity and FIFO queue.
        Schema::create('course_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->timestamps();
        });

        Schema::create('course_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_category_id')->constrained('course_categories')->restrictOnDelete();
            $table->string('name', 160);
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('waitlist_capacity')->default(5);
            $table->unsignedInteger('expected_event_count');
            $table->decimal('price_amount', 12, 2);
            $table->unsignedInteger('payment_hold_hours')->default(38);
            $table->string('status', 24)->default('draft'); // draft, open, closed
            $table->unsignedBigInteger('next_waitlist_sequence')->default(1);
            $table->timestamps();
            $table->index(['course_category_id', 'status']);
        });

        Schema::create('course_group_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_group_id')->constrained('course_groups')->restrictOnDelete();
            $table->foreignId('calendar_event_id')->constrained('calendar_events')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['course_group_id', 'calendar_event_id'], 'course_group_event_unique');
            $table->unique(['course_group_id', 'position'], 'course_group_position_unique');
            // MVP: one independent event belongs to at most one booking group.
            $table->unique('calendar_event_id', 'course_group_event_owner_unique');
        });

        Schema::create('course_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_group_id')->constrained('course_groups')->restrictOnDelete();
            $table->foreignId('member_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('booker_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24); // pending_payment, confirmed, waitlisted, cancelled, expired
            $table->unsignedBigInteger('waitlist_sequence')->nullable();
            $table->string('request_key', 100);
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['course_group_id', 'member_user_id'], 'course_group_member_unique');
            $table->unique(['course_group_id', 'request_key'], 'course_group_request_unique');
            $table->unique(['course_group_id', 'waitlist_sequence'], 'course_group_fifo_unique');
            $table->index(['course_group_id', 'status', 'waitlist_sequence'], 'course_group_queue_index');
        });

        // The invoice covers actual independently stored single-event bookings.
        Schema::create('invoice_booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('event_booking_group_id')->constrained('event_booking_groups')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'event_booking_group_id'], 'invoice_booking_item_unique');
            $table->unique('event_booking_group_id', 'invoice_booking_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_booking_items');
        Schema::dropIfExists('course_group_members');
        Schema::dropIfExists('course_group_events');
        Schema::dropIfExists('course_groups');
        Schema::dropIfExists('course_categories');
    }
};
