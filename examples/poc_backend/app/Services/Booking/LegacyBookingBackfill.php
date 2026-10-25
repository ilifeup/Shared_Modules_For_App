<?php

namespace App\Services\Booking;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * S9-L06 A: maps every confirmed S6 booking that has no group yet to a group + one
 * attendee, so the attendee table is the only seat count. Idempotent (re-runnable:
 * legacy_booking_id is unique) and verified (aborts on any count mismatch).
 */
final class LegacyBookingBackfill
{
    /**
     * @return int groups created by this run (0 when already done)
     */
    public static function run(): int
    {
        $now = now();
        $created = 0;

        DB::table('event_bookings')
            ->join('users', 'users.id', '=', 'event_bookings.user_id')
            ->where('event_bookings.status', 'confirmed')
            ->whereNotExists(fn ($q) => $q->from('event_booking_groups')->whereColumn('event_booking_groups.legacy_booking_id', 'event_bookings.id'))
            ->select('event_bookings.*', 'users.name as user_name', 'users.email as user_email', 'users.phone_e164 as user_phone')
            ->chunkById(500, function ($rows) use ($now, &$created): void {
                foreach ($rows as $row) {
                    $groupId = DB::table('event_booking_groups')->insertGetId([
                        'calendar_event_id' => $row->calendar_event_id,
                        'legacy_booking_id' => $row->id,
                        'booker_user_id' => $row->user_id,
                        'is_guest' => false,
                        'contact_name' => mb_substr((string) $row->user_name, 0, 80),
                        'contact_email' => $row->user_email,
                        'contact_phone' => $row->user_phone,
                        'status' => 'confirmed',
                        'seat_count' => 1,
                        'booked_at' => $row->booked_at,
                        'confirmed_at' => $row->booked_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $created++;
                    DB::table('event_booking_attendees')->insert([
                        'event_booking_group_id' => $groupId,
                        'calendar_event_id' => $row->calendar_event_id,
                        'member_user_id' => $row->user_id,
                        'name' => mb_substr((string) $row->user_name, 0, 80),
                        'status' => 'confirmed',
                        'active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }, 'event_bookings.id', 'id');

        $legacy = DB::table('event_bookings')->where('status', 'confirmed')->count();
        $mapped = DB::table('event_booking_attendees')
            ->join('event_booking_groups', 'event_booking_groups.id', '=', 'event_booking_attendees.event_booking_group_id')
            ->whereNotNull('event_booking_groups.legacy_booking_id')
            ->where('event_booking_attendees.status', 'confirmed')
            ->count();

        if ($legacy !== $mapped) {
            throw new RuntimeException("S9 booking backfill mismatch: {$legacy} confirmed bookings, {$mapped} attendees.");
        }

        return $created;
    }
}
