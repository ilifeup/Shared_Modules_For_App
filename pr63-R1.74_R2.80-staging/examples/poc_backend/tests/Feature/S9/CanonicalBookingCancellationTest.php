<?php

namespace Tests\Feature\S9;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\CanonicalBookingCancellation;
use App\Services\Booking\CanonicalBookingWriter;
use App\Support\Api\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cancellation + FIFO must be atomic; full audit/notification cutover remains pending. */
class CanonicalBookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_cancellation_promotes_fifo_head_in_same_transaction(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 1,
            'waitlist_enabled' => true,
            'waitlist_capacity' => 2,
            'price_amount' => 0,
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $confirmed = $writer->reserve($event, $members[0], $members[0]);
        $first = $writer->reserve($event, $members[1], $members[1]);
        $second = $writer->reserve($event, $members[2], $members[2]);

        app(CanonicalBookingCancellation::class)->cancel($confirmed, $members[0]);

        $this->assertSame(EventBooking::STATUS_CANCELLED, $confirmed->fresh()->status);
        $this->assertNull($confirmed->fresh()->active);
        $this->assertSame(EventBooking::STATUS_CONFIRMED, $first->fresh()->status);
        $this->assertSame(EventBooking::STATUS_WAITLISTED, $second->fresh()->status);
        $this->assertSame(1, EventBooking::query()->where('calendar_event_id', $event->id)
            ->where('status', EventBooking::STATUS_CONFIRMED)->where('active', 1)->count());
    }

    public function test_cancelled_waitlist_entry_keeps_sequence_and_next_entry_is_monotonic(): void
    {
        $members = User::factory()->count(4)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 1,
            'waitlist_enabled' => true,
            'waitlist_capacity' => 3,
            'price_amount' => 0,
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $cancelled = $writer->reserve($event, $members[1], $members[1]);
        $this->assertSame(1, (int) $cancelled->waitlist_sequence);

        app(CanonicalBookingCancellation::class)->cancel($cancelled, $members[1]);
        $this->assertSame(EventBooking::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(1, (int) $cancelled->fresh()->waitlist_sequence);

        $next = $writer->reserve($event, $members[2], $members[2]);
        $this->assertSame(2, (int) $next->waitlist_sequence);
    }

    public function test_waitlist_projection_is_cancelled_atomically_with_booking(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        // Writer creates this row atomically; do not insert a duplicate.
        $this->assertDatabaseHas('event_waitlist_entries', [
            'event_booking_id' => $waiting->id,
            'sequence' => $waiting->waitlist_sequence,
            'status' => 'waiting',
        ]);

        app(CanonicalBookingCancellation::class)->cancel($waiting, $members[1]);

        $this->assertSame(EventBooking::STATUS_CANCELLED, $waiting->fresh()->status);
        $this->assertSame('cancelled', \Illuminate\Support\Facades\DB::table('event_waitlist_entries')
            ->where('event_booking_id', $waiting->id)->value('status'));
    }

    public function test_revoked_actor_cannot_cancel_using_stale_user_model(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2, 'price_amount' => 0]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        // Keep the in-memory actor stale while revoking its persisted status.
        User::query()->whereKey($member->id)->update(['is_active' => false]);

        try {
            app(CanonicalBookingCancellation::class)->cancel($booking, $member);
            $this->fail('Revoked actor must not cancel through a stale model.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED, $booking->fresh()->status);
            $this->assertSame(1, $booking->fresh()->active);
        }
    }

    public function test_ineligible_fifo_head_rolls_back_cancellation_and_preserves_queue(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 1,
            'waitlist_enabled' => true,
            'waitlist_capacity' => 1,
            'price_amount' => 0,
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $confirmed = $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        $members[1]->forceFill(['is_active' => false])->save();

        try {
            app(CanonicalBookingCancellation::class)->cancel($confirmed, $members[0]);
            $this->fail('Cancellation must roll back when FIFO head cannot be promoted.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED, $confirmed->fresh()->status);
            $this->assertSame(1, $confirmed->fresh()->active);
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $waiting->fresh()->status);
            $this->assertSame(1, $waiting->fresh()->waitlist_sequence);
        }
    }

    public function test_waitlisted_member_can_withdraw_after_confirmed_cancellation_deadline(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);

        $this->travelTo($event->cancelClosesAt()->copy()->addSecond());
        try {
            app(CanonicalBookingCancellation::class)->cancel($waiting, $members[1]);
            $this->assertSame(EventBooking::STATUS_CANCELLED, $waiting->fresh()->status);
            $this->assertDatabaseHas('event_waitlist_entries', [
                'event_booking_id' => $waiting->id,
                'status' => 'cancelled',
            ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_confirmed_member_cannot_cancel_after_deadline(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1, 'price_amount' => 0]);
        $confirmed = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);

        $this->travelTo($event->cancelClosesAt()->copy()->addSecond());
        try {
            try {
                app(CanonicalBookingCancellation::class)->cancel($confirmed, $member);
                $this->fail('Confirmed cancellation must obey the deadline.');
            } catch (ApiException $exception) {
                $this->assertSame(EventBooking::STATUS_CONFIRMED, $confirmed->fresh()->status);
                $this->assertSame(1, (int) $confirmed->fresh()->active);
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_missing_waitlist_projection_blocks_cancellation_without_changing_booking(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2, 'price_amount' => 0]);
        $writer = app(CanonicalBookingWriter::class);
        $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        \Illuminate\Support\Facades\DB::table('event_waitlist_entries')
            ->where('event_booking_id', $waiting->id)->delete();

        try {
            app(CanonicalBookingCancellation::class)->cancel($waiting, $members[1]);
            $this->fail('Missing projection must block cancellation.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $waiting->fresh()->status);
            $this->assertSame(1, (int) $waiting->fresh()->active);
        }
    }
}
