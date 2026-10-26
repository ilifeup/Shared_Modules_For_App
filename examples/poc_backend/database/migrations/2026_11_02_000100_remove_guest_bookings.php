<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanently purge legacy guest groups and groups containing ID-less attendees.
     * Destructive by explicit product decision; run only after a verified DB backup.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('event_booking_groups')
                ->where('is_guest', true)
                ->orWhereNull('booker_user_id')
                ->orWhereIn('id', DB::table('event_booking_attendees')
                    ->select('event_booking_group_id')->whereNull('member_user_id'))
                ->pluck('id');

            foreach ($groups->chunk(200) as $ids) {
                $attendees = DB::table('event_booking_attendees')
                    ->whereIn('event_booking_group_id', $ids)->pluck('id');
                // Corrections cascade from attendances, but attendance itself RESTRICTs attendee deletion.
                foreach ($attendees->chunk(200) as $attendeeIds) {
                    DB::table('event_attendances')->whereIn('event_booking_attendee_id', $attendeeIds)->delete();
                }
                DB::table('event_waitlist_entries')->whereIn('event_booking_group_id', $ids)->delete();
                // Group is a morph-notifiable; no FK protects orphan notifications.
                DB::table('notifications')
                    ->where('notifiable_type', (new \App\Models\EventBookingGroup())->getMorphClass())
                    ->whereIn('notifiable_id', $ids)->delete();
                DB::table('event_booking_attendees')->whereIn('event_booking_group_id', $ids)->delete();
                DB::table('event_booking_groups')->whereIn('id', $ids)->delete();
            }
        });

        Schema::table('event_categories', function (Blueprint $table): void {
            $table->dropColumn('allow_guest_booking');
        });
        Schema::table('event_booking_groups', function (Blueprint $table): void {
            $table->dropIndex(['contact_email']);
            $table->dropIndex(['contact_phone']);
            $table->dropColumn(['is_guest', 'claimed_at']);
        });
        // Keep contact_email/contact_phone: these are also used for legitimate member bookings.
        // Keep nullable FK definitions until a separate cross-database FK migration is validated.
    }

    public function down(): void
    {
        throw new \RuntimeException('Guest booking purge is irreversible; restore the verified database backup instead.');
    }
};
