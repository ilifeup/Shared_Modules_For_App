<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_terms', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('location')->nullable();
            $table->string('floor_landmark')->nullable();
            $table->string('studio')->nullable();
            $table->unsignedInteger('enrollment_capacity');
            $table->unsignedInteger('waitlist_capacity')->default(5);
            $table->unsignedInteger('cancel_cutoff_days')->default(10);
            // Administrators schedule the opening of term enrollment (UTC).
            $table->timestamp('booking_opens_at')->nullable();
            $table->timestamps();
        });

        Schema::create('course_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_event_id')->unique()->constrained('calendar_events')->restrictOnDelete();
            $table->unsignedInteger('makeup_capacity')->default(1);
            $table->timestamps();
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('booker_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24); // pending_payment, confirmed, waitlisted, cancelled
            $table->unsignedBigInteger('waitlist_sequence')->nullable();
            $table->timestamp('fee_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['course_term_id', 'user_id']);
            $table->index(['course_term_id', 'status', 'waitlist_sequence']);
        });

        Schema::create('course_session_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_session_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('requested_at');
            $table->timestamps();
            $table->unique(['course_enrollment_id', 'course_session_id'], 'course_leave_enrollment_session_uq');
        });

        Schema::create('course_makeups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_session_leave_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('target_course_session_id')->constrained('course_sessions')->restrictOnDelete();
            $table->foreignId('event_booking_group_id')->unique()->constrained('event_booking_groups')->restrictOnDelete();
            $table->timestamps();
            $table->index('target_course_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_makeups');
        Schema::dropIfExists('course_session_leaves');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('course_sessions');
        Schema::dropIfExists('course_terms');
    }
};
