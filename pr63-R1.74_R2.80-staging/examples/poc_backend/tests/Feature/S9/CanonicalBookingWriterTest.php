<?php

namespace Tests\Feature\S9;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\CanonicalBookingWriter;
use App\Support\Api\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Isolated canonical writer tests; not a replacement for BookingEngine E2E. */
class CanonicalBookingWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_uses_one_canonical_row_and_replays_same_identity(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $writer = app(CanonicalBookingWriter::class);
        $key = 'd97a89f7-9380-42f5-8e23-ec9c0713dd91';

        $first = $writer->reserve($event, $member, $member, $key);
        $second = $writer->reserve($event, $member, $member, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($member->id, $first->member_user_id);
        $this->assertSame($member->id, $first->booker_user_id);
        $this->assertSame(EventBooking::STATUS_CONFIRMED, $first->status);
        $this->assertSame(1, EventBooking::query()->where('calendar_event_id', $event->id)->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('event_booking_groups')
            ->where('calendar_event_id', $event->id)->count());
    }

    public function test_null_capacity_does_not_mean_zero_seats(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => null]);
        $writer = app(CanonicalBookingWriter::class);

        foreach ($members as $member) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED,
                $writer->reserve($event, $member, $member, null, false)->status);
        }
    }

    public function test_revoked_member_is_rejected_even_if_caller_holds_stale_user_model(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        // The caller retains a previously loaded verified model.
        User::query()->whereKey($member->id)->update(['verified_status' => 0]);

        $this->expectException(ApiException::class);
        try {
            app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        } finally {
            $this->assertSame(0, EventBooking::query()
                ->where('calendar_event_id', $event->id)->count());
        }
    }

    public function test_duplicate_member_without_matching_request_key_is_rejected(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $member, $member);

        $this->expectException(ApiException::class);
        $writer->reserve($event, $member, $member);
    }

    public function test_waitlist_is_one_member_per_row_in_fifo_sequence(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2,
        ]);
        $writer = app(CanonicalBookingWriter::class);

        $bookings = $members->map(fn (User $member) => $writer->reserve($event, $member, $member));
        $this->assertSame([
            EventBooking::STATUS_CONFIRMED,
            EventBooking::STATUS_WAITLISTED,
            EventBooking::STATUS_WAITLISTED,
        ], $bookings->pluck('status')->all());
        $this->assertSame([null, 1, 2], $bookings->pluck('waitlist_sequence')->all());
    }
    public function test_null_waitlist_capacity_does_not_block_waiting_members(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => null,
        ]);
        $writer = app(CanonicalBookingWriter::class);

        $bookings = $members->map(fn (User $member) => $writer->reserve($event, $member, $member));
        $this->assertSame([
            EventBooking::STATUS_CONFIRMED,
            EventBooking::STATUS_WAITLISTED,
            EventBooking::STATUS_WAITLISTED,
        ], $bookings->pluck('status')->all());
        $this->assertSame([null, 1, 2], $bookings->pluck('waitlist_sequence')->all());
    }

    public function test_duplicate_active_waitlist_sequence_blocks_new_queue_entry(): void
    {
        $members = User::factory()->count(4)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => null]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $first = $writer->reserve($event, $members[1], $members[1]);
        $second = $writer->reserve($event, $members[2], $members[2]);
        $second->forceFill(['waitlist_sequence' => $first->waitlist_sequence])->save();

        $this->expectException(ApiException::class);
        try {
            $writer->reserve($event, $members[3], $members[3]);
        } finally {
            $this->assertSame(3, EventBooking::query()
                ->where('calendar_event_id', $event->id)->count());
        }
    }

    public function test_waitlist_sequence_continues_after_cancelled_history(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => null]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $historical = $writer->reserve($event, $members[1], $members[1]);
        $historical->forceFill(['status' => EventBooking::STATUS_CANCELLED,
            'active' => null, 'cancelled_at' => now()])->save();

        $next = $writer->reserve($event, $members[2], $members[2]);
        $this->assertSame(EventBooking::STATUS_WAITLISTED, $next->status);
        $this->assertSame(1, (int) $historical->fresh()->waitlist_sequence);
        $this->assertSame(2, (int) $next->waitlist_sequence);
    }

    public function test_cancelled_request_key_cannot_be_replayed_as_active_reservation(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $key = '8e14b19c-3bc7-44b4-99c2-00a74b601821';
        $writer = app(CanonicalBookingWriter::class);
        $booking = $writer->reserve($event, $member, $member, $key);
        $booking->forceFill([
            'status' => EventBooking::STATUS_CANCELLED,
            'active' => null,
            'cancelled_at' => now(),
        ])->save();

        try {
            $writer->reserve($event, $member, $member, $key);
            $this->fail('An inactive booking cannot be returned as a successful replay.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CANCELLED, $booking->fresh()->status);
            $this->assertSame(1, EventBooking::query()->where('calendar_event_id', $event->id)->count());
        }
    }

    public function test_cancelled_event_cannot_return_stale_active_booking_on_idempotent_replay(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $key = '2dc3bc70-56b5-40a0-8cc4-221c3a095a81';
        $writer = app(CanonicalBookingWriter::class);
        $booking = $writer->reserve($event, $member, $member, $key);
        $event->forceFill(['status' => CalendarEvent::STATUS_CANCELLED])->save();

        $this->expectException(ApiException::class);
        try {
            $writer->reserve($event->fresh(), $member, $member, $key);
        } finally {
            $this->assertSame(1, EventBooking::query()
                ->where('calendar_event_id', $event->id)->count());
            $this->assertSame($booking->id, $booking->fresh()->id);
        }
    }

    public function test_paid_event_cannot_create_unbilled_canonical_seat(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2, 'price_amount' => 100]);
        try {
            app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
            $this->fail('Paid canonical booking must be blocked until invoice integration.');
        } catch (ApiException $exception) {
            $this->assertSame(0, EventBooking::query()->where('calendar_event_id', $event->id)->count());
        }
    }

    public function test_idempotent_replay_is_blocked_if_event_becomes_paid_without_canonical_billing(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $key = 'c24c9c0c-9dc3-4429-bbb8-b643d2c98900';
        $first = $writer->reserve($event, $member, $member, $key);
        $event->forceFill(['price_amount' => 100])->save();

        $this->expectException(ApiException::class);
        $writer->reserve($event->fresh(), $member, $member, $key);
    }

    public function test_paid_confirmed_booking_creates_one_canonical_invoice_and_replay_does_not_duplicate_it(): void
    {
        config()->set('booking.canonical_paid_cutover_ready', true);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 2,
            'price_amount' => 150,
            'starts_at' => now()->addDays(3),
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $key = '70cc912d-8e71-44d8-8b1d-a1cb7d3b79bb';

        $first = $writer->reserve($event, $member, $member, $key);
        $replay = $writer->reserve($event, $member, $member, $key);

        $this->assertSame($first->id, $replay->id);
        $invoices = \Illuminate\Support\Facades\DB::table('invoices')
            ->where('booking_type', \App\Services\Payment\CanonicalEventInvoiceService::BOOKING_TYPE)
            ->where('booking_id', $first->id)->get();
        $this->assertCount(1, $invoices);
        $this->assertSame((int) $event->id, (int) $invoices[0]->product_id);
        $this->assertSame((int) $member->id, (int) $invoices[0]->member_id);
        $this->assertSame((int) $member->id, (int) $invoices[0]->booker_user_id);
        $this->assertSame(0, bccomp((string) $invoices[0]->invoice_amount, '150.00', 2));
    }

    public function test_paid_booking_rolls_back_seat_when_invoice_cannot_be_created(): void
    {
        config()->set('booking.canonical_paid_cutover_ready', true);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        // The booking window may be open even if the invoice deadline cannot
        // be placed in the future; both rows must roll back together.
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 1,
            'price_amount' => 150,
            'starts_at' => now()->subMinute(),
            'booking_closes_at' => now()->addHour(),
        ]);

        try {
            app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
            $this->fail('A paid seat must not survive failed invoice creation.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertSame(0, EventBooking::query()->where('calendar_event_id', $event->id)->count());
            $this->assertSame(0, \Illuminate\Support\Facades\DB::table('invoices')
                ->where('product_id', $event->id)
                ->where('booking_type', \App\Services\Payment\CanonicalEventInvoiceService::BOOKING_TYPE)
                ->count());
        }
    }

}
