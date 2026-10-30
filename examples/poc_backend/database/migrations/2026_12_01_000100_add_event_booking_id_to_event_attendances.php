<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive R1 transition: preserve legacy attendance records until a
     * separately validated attendee-to-booking backfill is available.
     */
    public function up(): void
    {
        Schema::table('event_attendances', function (Blueprint $table) {
            $table->foreignId('event_booking_id')
                ->nullable()
                ->constrained('event_bookings')
                ->restrictOnDelete();

            // One attendance per canonical booking; NULL legacy rows coexist.
            $table->unique('event_booking_id', 'event_attendances_booking_unique');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendances', function (Blueprint $table) {
            $table->dropUnique('event_attendances_booking_unique');
            $table->dropForeign(['event_booking_id']);
            $table->dropColumn('event_booking_id');
        });
    }
};
