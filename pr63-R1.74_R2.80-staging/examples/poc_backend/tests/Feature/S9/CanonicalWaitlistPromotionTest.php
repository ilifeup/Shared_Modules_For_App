<?php

namespace Tests\Feature\S9;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Booking\CanonicalBookingWriter;
use App\Services\Booking\CanonicalWaitlistPromotion;
use App\Support\Api\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Isolated FIFO tests; full BookingEngine accounting integration remains pending. */
class CanonicalWaitlistPromotionTest extends TestCase
{
    use RefreshDatabase;

    public function test_promotion_requires_owning_transaction(): void
    {
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1]);
        $this->expectException(ApiException::class);
        app(CanonicalWaitlistPromotion::class)->promoteLocked($event);
    }

    public function test_promotes_only_fifo_head_when_one_seat_is_released(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true,
            'booking_capacity' => 1,
            'waitlist_enabled' => true,
            'waitlist_capacity' => 2,
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $bookings = $members->map(fn (User $member) => $writer->reserve($event, $member, $member));

        $promoted = DB::transaction(function () use ($event, $bookings): array {
            // Test-only release; production cancellation must also handle invoices and sync.
            $bookings[0]->forceFill(['status' => EventBooking::STATUS_CANCELLED, 'active' => null])->save();
            return app(CanonicalWaitlistPromotion::class)->promoteLocked($event);
        });

        $this->assertCount(1, $promoted);
        $this->assertSame($bookings[1]->id, $promoted[0]->id);
        $this->assertSame(EventBooking::STATUS_CONFIRMED, $bookings[1]->fresh()->status);
        $this->assertSame(1, (int) $bookings[1]->fresh()->waitlist_sequence);
        $this->assertSame(EventBooking::STATUS_WAITLISTED, $bookings[2]->fresh()->status);
    }
    public function test_missing_waitlist_projection_blocks_promotion_and_rolls_back_release(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 1]);
        $writer = app(CanonicalBookingWriter::class);
        $confirmed = $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        DB::table('event_waitlist_entries')->where('event_booking_id', $waiting->id)->delete();

        try {
            DB::transaction(function () use ($event, $confirmed): void {
                $confirmed->forceFill(['status' => EventBooking::STATUS_CANCELLED, 'active' => null])->save();
                app(CanonicalWaitlistPromotion::class)->promoteLocked($event);
            });
            $this->fail('A missing FIFO projection must block promotion.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED, $confirmed->fresh()->status);
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $waiting->fresh()->status);
        }
    }

    public function test_duplicate_fifo_sequence_blocks_promotion_and_rolls_back(): void
    {
        $members = User::factory()->count(3)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 2]);
        $writer = app(CanonicalBookingWriter::class);
        $confirmed = $writer->reserve($event, $members[0], $members[0]);
        $first = $writer->reserve($event, $members[1], $members[1]);
        $second = $writer->reserve($event, $members[2], $members[2]);
        $second->forceFill(['waitlist_sequence' => $first->waitlist_sequence])->save();

        try {
            DB::transaction(function () use ($event, $confirmed): void {
                $confirmed->forceFill(['status' => EventBooking::STATUS_CANCELLED, 'active' => null])->save();
                app(CanonicalWaitlistPromotion::class)->promoteLocked($event);
            });
            $this->fail('Duplicate FIFO positions must block promotion.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED, $confirmed->fresh()->status);
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $first->fresh()->status);
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $second->fresh()->status);
        }
    }

    public function test_paid_event_blocks_unbilled_promotion_and_rolls_back_release(): void
    {
        $members = User::factory()->count(2)->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event([
            'booking_enabled' => true, 'booking_capacity' => 1,
            'waitlist_enabled' => true, 'waitlist_capacity' => 1,
            'price_amount' => 0,
        ]);
        $writer = app(CanonicalBookingWriter::class);
        $confirmed = $writer->reserve($event, $members[0], $members[0]);
        $waiting = $writer->reserve($event, $members[1], $members[1]);
        $event->forceFill(['price_amount' => 100])->save();

        try {
            DB::transaction(function () use ($event, $confirmed): void {
                $confirmed->forceFill(['status' => EventBooking::STATUS_CANCELLED, 'active' => null])->save();
                app(CanonicalWaitlistPromotion::class)->promoteLocked($event);
            });
            $this->fail('Paid event must not promote a member without invoice integration.');
        } catch (ApiException $exception) {
            $this->assertSame(EventBooking::STATUS_CONFIRMED, $confirmed->fresh()->status);
            $this->assertSame(1, $confirmed->fresh()->active);
            $this->assertSame(EventBooking::STATUS_WAITLISTED, $waiting->fresh()->status);
        }
    }

}
