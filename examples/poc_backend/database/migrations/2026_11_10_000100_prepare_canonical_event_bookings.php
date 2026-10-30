<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 phase 1: add canonical single-member booking identities without removing
 * the S6/S9 columns or tables still consumed by the existing application.
 *
 * This is deliberately additive. A later coordinated cutover will migrate S9
 * groups, replace dependent foreign keys, and retire the legacy seat ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_bookings', function (Blueprint $table): void {
            $table->foreignId('member_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('booker_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('client_request_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('cancel_reason')->nullable();
        });

        // Existing S6 reservations were always self-booked. Preserve the
        // original booker, member, status, cancellation history and primary ID.
        DB::table('event_bookings')->whereNull('member_user_id')
            ->update([
                'member_user_id' => DB::raw('user_id'),
                'booker_user_id' => DB::raw('user_id'),
            ]);
        DB::table('event_bookings')->where('status', 'confirmed')
            ->whereNull('confirmed_at')->update(['confirmed_at' => DB::raw('booked_at')]);

        Schema::table('event_bookings', function (Blueprint $table): void {
            // NULL active allows multiple cancelled historical rows, while
            // confirmed and waitlisted bookings share one active member slot.
            $table->unique(
                ['calendar_event_id', 'member_user_id', 'active'],
                'event_bookings_member_active_unique',
            );
            $table->unique('client_request_id', 'event_bookings_request_unique');
            $table->index(['booker_user_id', 'booked_at'], 'event_bookings_booker_history_index');
        });
    }

    public function down(): void
    {
        Schema::table('event_bookings', function (Blueprint $table): void {
            $table->dropUnique('event_bookings_member_active_unique');
            $table->dropUnique('event_bookings_request_unique');
            $table->dropIndex('event_bookings_booker_history_index');
            $table->dropConstrainedForeignId('member_user_id');
            $table->dropConstrainedForeignId('booker_user_id');
            $table->dropColumn(['client_request_id', 'confirmed_at', 'cancel_reason']);
        });
    }
};
