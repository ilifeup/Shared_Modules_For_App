<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Booking\CanonicalBookingOutbox;
use App\Services\Booking\CanonicalBookingWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Transaction and idempotency regression coverage for the durable booking outbox. */
class CanonicalBookingOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsupported_transition_is_rejected_without_inserting_event(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        $before = DB::table('canonical_booking_outbox')->where('event_booking_id', $booking->id)->count();

        try {
            DB::transaction(fn () => app(CanonicalBookingOutbox::class)->record($booking, 'unknown'));
            $this->fail('An unsupported transition must be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('Unsupported booking transition.', $error->getMessage());
        }

        $this->assertSame($before, DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)->count());
    }

    public function test_repeated_same_transition_creates_only_one_outbox_event(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);

        DB::transaction(function () use ($booking): void {
            $outbox = app(CanonicalBookingOutbox::class);
            $outbox->record($booking, 'confirmed');
            $outbox->record($booking, 'confirmed');
        });

        $this->assertSame(1, DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)->where('transition', 'confirmed')->count());
    }

    public function test_outbox_event_rolls_back_with_parent_transaction(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        // reserve() already writes a confirmed outbox event. Test a *new*
        // transition, not the existing confirmed event, to verify rollback.
        $before = DB::table('canonical_booking_outbox')->where('event_booking_id', $booking->id)->count();

        try {
            DB::transaction(function () use ($booking): void {
                app(CanonicalBookingOutbox::class)->record($booking, 'cancelled');
                throw new \RuntimeException('rollback');
            });
            $this->fail('The transaction should have thrown.');
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback', $error->getMessage());
        }

        $this->assertSame($before, DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)->count());
        $this->assertSame(0, DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)->where('transition', 'cancelled')->count());
    }
    public function test_event_cancelled_and_member_cancelled_have_distinct_durable_events(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);

        DB::transaction(function () use ($booking): void {
            $outbox = app(CanonicalBookingOutbox::class);
            $outbox->record($booking, 'cancelled');
            $outbox->record($booking, 'event_cancelled');
            $outbox->record($booking, 'event_cancelled');
        });

        $events = DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)
            ->pluck('transition')->all();
        $this->assertCount(3, $events);
        $this->assertContains('confirmed', $events);
        $this->assertContains('cancelled', $events);
        $this->assertContains('event_cancelled', $events);
    }

    public function test_outbox_record_keeps_event_pending_until_consumer_processes_it(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);

        $row = DB::table('canonical_booking_outbox')
            ->where('event_booking_id', $booking->id)
            ->where('transition', 'confirmed')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->state);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->delivered_at);
    }

}
