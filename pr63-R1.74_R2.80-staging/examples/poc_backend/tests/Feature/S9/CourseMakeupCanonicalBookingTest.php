<?php

namespace Tests\Feature\S9;

use App\Models\EventBooking;
use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use App\Services\Course\CourseMakeupAssignmentService;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Canonical makeup contract; complements the legacy tests, which must be
 * replaced together with BookingEngine and the historical data migration.
 */
class CourseMakeupCanonicalBookingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $makeupCapacity = 1, int $eventCapacity = 3): array
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $admin = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $admin->assignRole(User::ADMIN_ROLE);
        $term = DB::table('course_terms')->insertGetId([
            'title' => 'Canonical makeup source', 'price_amount' => 1,
            'enrollment_capacity' => 2, 'waitlist_capacity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $originalStart = now()->addDays(7);
        $original = s9Event([
            'booking_capacity' => 3, 'starts_at' => $originalStart,
            'ends_at' => $originalStart->copy()->addHour(),
        ]);
        $targetStart = now()->addDays(14);
        $target = s9Event([
            'booking_capacity' => $eventCapacity, 'starts_at' => $targetStart,
            'ends_at' => $targetStart->copy()->addHour(),
        ]);
        $targetTerm = DB::table('course_terms')->insertGetId([
            'title' => 'Canonical makeup target', 'price_amount' => 1,
            'enrollment_capacity' => 2, 'waitlist_capacity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$original, $term], [$target, $targetTerm]] as [$event, $sessionTerm]) {
            DB::table('course_sessions')->insert([
                'course_term_id' => $sessionTerm, 'calendar_event_id' => $event->id,
                'makeup_capacity' => $makeupCapacity,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        $invoiceId = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->value('id');
        app(CashInvoiceService::class)->confirmCash((int) $invoiceId, $admin);
        $originalSession = DB::table('course_sessions')
            ->where('calendar_event_id', $original->id)->value('id');
        $targetSession = DB::table('course_sessions')
            ->where('calendar_event_id', $target->id)->value('id');
        $leave = DB::table('course_session_leaves')->insertGetId([
            'course_enrollment_id' => $enrollment['id'],
            'course_session_id' => $originalSession,
            'reason' => 'Canonical makeup leave',
            'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('member', 'admin', 'target', 'leave', 'targetSession');
    }

    public function test_makeup_creates_exactly_one_canonical_booking_and_is_idempotent(): void
    {
        $f = $this->fixture();
        $service = app(CourseMakeupAssignmentService::class);
        $first = $service->assign($f['leave'], $f['targetSession'], $f['admin']);
        $second = $service->assign($f['leave'], $f['targetSession'], $f['admin']);

        $this->assertSame($first->id, $second->id);
        $this->assertNotNull($first->event_booking_id);
        $this->assertSame(1, EventBooking::query()
            ->whereKey($first->event_booking_id)
            ->where('calendar_event_id', $f['target']->id)
            ->where('member_user_id', $f['member']->id)
            ->where('status', EventBooking::STATUS_CONFIRMED)
            ->where('active', 1)->count());
        $this->assertSame(1, DB::table('course_makeups')->count());
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('calendar_event_id', $f['target']->id)->count());
    }

    public function test_zero_makeup_capacity_does_not_leave_partial_booking(): void
    {
        $f = $this->fixture(makeupCapacity: 0);
        $before = EventBooking::query()->where('calendar_event_id', $f['target']->id)->count();

        try {
            app(CourseMakeupAssignmentService::class)->assign($f['leave'], $f['targetSession'], $f['admin']);
            $this->fail('Zero makeup capacity must reject.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_course_session_id', $e->errors());
        }

        $this->assertSame($before, EventBooking::query()
            ->where('calendar_event_id', $f['target']->id)->count());
        $this->assertSame(0, DB::table('course_makeups')->count());
    }

    public function test_full_canonical_event_rejects_makeup(): void
    {
        $f = $this->fixture(makeupCapacity: 2, eventCapacity: 1);
        $other = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        EventBooking::query()->create([
            'calendar_event_id' => $f['target']->id,
            'user_id' => $other->id,
            'member_user_id' => $other->id,
            'booker_user_id' => $other->id,
            'status' => EventBooking::STATUS_CONFIRMED,
            'active' => 1,
            'booked_at' => now(),
            'confirmed_at' => now(),
        ]);

        try {
            app(CourseMakeupAssignmentService::class)->assign($f['leave'], $f['targetSession'], $f['admin']);
            $this->fail('Full canonical event must reject makeup.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('target_course_session_id', $e->errors());
        }
        $this->assertSame(0, DB::table('course_makeups')->count());
    }
}
