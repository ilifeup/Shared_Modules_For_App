<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Booking\CanonicalBookingWriter;
use App\Services\Booking\CanonicalCutoverAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalCutoverAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_waitlist_reservation_creates_matching_projection_atomically(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);

        $this->assertDatabaseHas('event_waitlist_entries', [
            'calendar_event_id' => $event->id,
            'event_booking_id' => $waiting->id,
            'sequence' => $waiting->waitlist_sequence,
            'status' => 'waiting',
        ]);
    }

    public function test_detects_waiting_projection_sequence_mismatch_using_actual_sequence_column(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        // Writer already inserts the projection. Corrupt that row in place:
        // inserting another would violate the canonical unique index first.
        DB::table('event_waitlist_entries')->where('event_booking_id', $waiting->id)
            ->update(['sequence' => (int) $waiting->waitlist_sequence + 1, 'updated_at' => now()]);

        $result = app(CanonicalCutoverAudit::class)->inspect($event);
        $this->assertContains('waiting_projection_sequence_mismatch', $result['issues']);
        $this->assertFalse($result['ready_for_free_canonical_booking']);
    }

    public function test_detects_waiting_projection_linked_to_confirmed_booking(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2, 'price_amount' => 0]);
        $confirmed = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        DB::table('event_waitlist_entries')->insert([
            'calendar_event_id' => $event->id, 'event_booking_id' => $confirmed->id,
            'sequence' => 1, 'status' => 'waiting',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CanonicalCutoverAudit::class)->inspect($event);
        $this->assertContains('waiting_projection_invalid_booking', $result['issues']);
    }
}
