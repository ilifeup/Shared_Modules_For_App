<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Booking\CanonicalBookingWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Verify delivery remains gated and malformed operator arguments are rejected. */
class CanonicalBookingOutboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_is_disabled_by_default(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', false);
        $this->artisan('booking:deliver-canonical-outbox')
            ->expectsOutput('Canonical outbox delivery is disabled.')
            ->assertExitCode(1);
    }

    public function test_invalid_batch_size_is_rejected_before_delivery(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        $this->artisan('booking:deliver-canonical-outbox', ['--limit' => '1001'])
            ->expectsOutput('--limit must be between 1 and 1000.')
            ->assertExitCode(1);
    }

    public function test_manual_retry_requires_separate_approval_gate(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        config()->set('booking.canonical_outbox_manual_retry_ready', false);
        $this->artisan('booking:deliver-canonical-outbox', ['--retry-failed' => '1'])
            ->expectsOutput('Manual retry of failed outbox events is disabled.')
            ->assertExitCode(1);
    }

    public function test_invalid_failed_event_id_is_rejected(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        config()->set('booking.canonical_outbox_manual_retry_ready', true);
        $this->artisan('booking:deliver-canonical-outbox', ['--retry-failed' => '-2'])
            ->expectsOutput('--retry-failed must be a positive integer event ID.')
            ->assertExitCode(1);
    }

    public function test_failed_event_can_be_reset_without_immediate_delivery(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        config()->set('booking.canonical_outbox_manual_retry_ready', true);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        $id = DB::table('canonical_booking_outbox')->where('event_booking_id', $booking->id)->value('id');
        DB::table('canonical_booking_outbox')->where('id', $id)->update([
            'state' => 'failed', 'attempts' => 10, 'last_error' => 'temporary failure',
        ]);

        $this->artisan('booking:deliver-canonical-outbox', ['--retry-failed' => (string) $id])
            ->assertExitCode(0);

        $row = DB::table('canonical_booking_outbox')->where('id', $id)->first();
        $this->assertSame('pending', $row->state);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertNull($row->delivered_at);
    }

    public function test_failed_event_cannot_be_reset_after_booking_state_changes(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        config()->set('booking.canonical_outbox_manual_retry_ready', true);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        $id = DB::table('canonical_booking_outbox')->where('event_booking_id', $booking->id)->value('id');
        DB::table('canonical_booking_outbox')->where('id', $id)->update([
            'state' => 'failed', 'attempts' => 10,
        ]);
        DB::table('event_bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);

        $this->artisan('booking:deliver-canonical-outbox', ['--retry-failed' => (string) $id])
            ->assertExitCode(1);

        $row = DB::table('canonical_booking_outbox')->where('id', $id)->first();
        $this->assertSame('failed', $row->state);
        $this->assertSame(10, (int) $row->attempts);
    }

    public function test_dry_run_lists_pending_events_without_mutating_or_notifying(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $event = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $booking = app(CanonicalBookingWriter::class)->reserve($event, $member, $member);
        $row = DB::table('canonical_booking_outbox')->where('event_booking_id', $booking->id)->first();
        $notificationsBefore = DB::table('notifications')->count();

        $this->artisan('booking:deliver-canonical-outbox', ['--dry-run' => true])
            ->expectsOutput('Pending outbox events eligible for delivery: 1')
            ->expectsOutput('Event ID: ' . $row->id)
            ->assertExitCode(0);

        $after = DB::table('canonical_booking_outbox')->where('id', $row->id)->first();
        $this->assertSame('pending', $after->state);
        $this->assertSame((int) $row->attempts, (int) $after->attempts);
        $this->assertNull($after->delivered_at);
        $this->assertSame($notificationsBefore, DB::table('notifications')->count());
    }

    public function test_dry_run_cannot_reset_failed_events(): void
    {
        config()->set('booking.canonical_outbox_delivery_ready', true);
        $this->artisan('booking:deliver-canonical-outbox', [
            '--dry-run' => true, '--retry-failed' => '1',
        ])->expectsOutput('--dry-run cannot be combined with --retry-failed.')
            ->assertExitCode(1);
    }

}
