<?php

use App\Models\EventBookingGroup;
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
        // Fail closed on production. This flag is an explicit operator gate,
        // NOT proof of a backup: verify a restore before setting it.
        // CI/disposable databases still exercise the migration.
        if (app()->environment('production') && env('GUEST_PURGE_BACKUP_VERIFIED') !== 'yes') {
            throw new RuntimeException('Guest booking purge blocked: verify a restorable database backup and set GUEST_PURGE_BACKUP_VERIFIED=yes for the migration run.');
        }

        // MySQL DDL implicitly commits: the transaction below only protects row deletion,
        // not subsequent DROP COLUMN / ALTER TABLE operations.
        // This migration must be deployed only after a verified, restorable backup.
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
                    ->where('notifiable_type', (new EventBookingGroup)->getMorphClass())
                    ->whereIn('notifiable_id', $ids)->delete();
                DB::table('event_booking_attendees')->whereIn('event_booking_group_id', $ids)->delete();
                DB::table('event_booking_groups')->whereIn('id', $ids)->delete();
            }
        });

        Schema::table('event_categories', function (Blueprint $table): void {
            $table->dropColumn('allow_guest_booking');
        });
        Schema::table('event_booking_groups', function (Blueprint $table): void {
            $table->dropColumn(['is_guest', 'claimed_at']);
        });
        // Keep contact_email/contact_phone: these are also used for legitimate member bookings.
        // Database-level constraints: no booking group or attendee may exist without a member.
        // Existing guest rows have already been purged above.
        Schema::table('event_booking_groups', function (Blueprint $table): void {
            $table->dropForeign(['booker_user_id']);
        });
        Schema::table('event_booking_attendees', function (Blueprint $table): void {
            $table->dropForeign(['member_user_id']);
        });
        Schema::table('event_booking_groups', function (Blueprint $table): void {
            $table->unsignedBigInteger('booker_user_id')->nullable(false)->change();
            $table->foreign('booker_user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('event_booking_attendees', function (Blueprint $table): void {
            $table->unsignedBigInteger('member_user_id')->nullable(false)->change();
            $table->foreign('member_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Guest booking purge is irreversible; restore the verified database backup instead.');
    }
};
