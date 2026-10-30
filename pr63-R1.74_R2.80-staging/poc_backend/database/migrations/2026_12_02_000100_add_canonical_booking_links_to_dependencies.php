<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Additive bridge only. Existing historical references are not dropped here.
 * A separately audited backfill must run before cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['event_waitlist_entries', 'course_makeups', 'invoice_booking_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('event_booking_id')->nullable()
                    ->constrained('event_bookings')->restrictOnDelete();
            });
        }

        // Attendance is also a cancellation dependency. Earlier M11 schemas
        // may already have this link; add it only when absent.
        if (Schema::hasTable('event_attendances')
            && ! Schema::hasColumn('event_attendances', 'event_booking_id')) {
            Schema::table('event_attendances', function (Blueprint $table) {
                $table->foreignId('event_booking_id')->nullable()
                    ->constrained('event_bookings')->restrictOnDelete();
            });
        }

        Schema::table('event_waitlist_entries', function (Blueprint $table) {
            $table->unique('event_booking_id', 'event_waitlist_booking_unique');
        });
        Schema::table('course_makeups', function (Blueprint $table) {
            $table->unique('event_booking_id', 'course_makeups_booking_unique');
        });
        Schema::table('invoice_booking_items', function (Blueprint $table) {
            // One booking can own multiple invoice line items. Do not impose uniqueness.
            $table->index('event_booking_id', 'invoice_booking_owner_canonical_index');
        });
    }

    public function down(): void
    {
        // Attendance may be owned by an earlier M11 migration. Never drop its
        // canonical link here; block rollback if mapped rows exist.
        if (Schema::hasTable('event_attendances')
            && Schema::hasColumn('event_attendances', 'event_booking_id')
            && DB::table('event_attendances')->whereNotNull('event_booking_id')->exists()) {
            throw new RuntimeException('Cannot roll back canonical links while attendance contains mapped rows.');
        }

        // A rollback after canonical links are populated would destroy the
        // migration mapping. Fail closed instead of silently losing references.
        foreach (['event_waitlist_entries', 'course_makeups', 'invoice_booking_items'] as $tableName) {
            if (DB::table($tableName)->whereNotNull('event_booking_id')->exists()) {
                throw new RuntimeException(
                    'Cannot roll back canonical booking links while '.$tableName.' contains mapped rows.'
                );
            }
        }

        Schema::table('invoice_booking_items', function (Blueprint $table) {
            $table->dropIndex('invoice_booking_owner_canonical_index');
            $table->dropConstrainedForeignId('event_booking_id');
        });
        Schema::table('course_makeups', function (Blueprint $table) {
            $table->dropUnique('course_makeups_booking_unique');
            $table->dropConstrainedForeignId('event_booking_id');
        });
        Schema::table('event_waitlist_entries', function (Blueprint $table) {
            $table->dropUnique('event_waitlist_booking_unique');
            $table->dropConstrainedForeignId('event_booking_id');
        });
    }
};
