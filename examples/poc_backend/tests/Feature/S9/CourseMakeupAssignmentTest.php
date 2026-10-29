<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use App\Services\Course\CourseMakeupAssignmentService;
use App\Services\Payment\CashInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CourseMakeupAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $makeupCapacity = 1, int $eventCapacity = 3): array
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $admin = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $admin->assignRole(User::ADMIN_ROLE);
        $term = DB::table('course_terms')->insertGetId([
            'title' => 'Makeup assignment test', 'price_amount' => 1,
            'enrollment_capacity' => 2, 'waitlist_capacity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $original = s9Event(['booking_capacity' => 3, 'starts_at' => now()->addDays(7)]);
        $target = s9Event(['booking_capacity' => $eventCapacity, 'starts_at' => now()->addDays(14)]);
        // The original term enrollment already books all its own sessions.
        // A makeup therefore needs a different, unbooked target occurrence.
        $targetTerm = DB::table('course_terms')->insertGetId([
            'title' => 'Separate makeup target term', 'price_amount' => 1,
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
        $invoice = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->value('id');
        app(CashInvoiceService::class)->confirmCash((int) $invoice, $admin);
        $originalSession = DB::table('course_sessions')->where('calendar_event_id', $original->id)->value('id');
        $targetSession = DB::table('course_sessions')->where('calendar_event_id', $target->id)->value('id');
        $leave = DB::table('course_session_leaves')->insertGetId([
            'course_enrollment_id' => $enrollment['id'], 'course_session_id' => $originalSession,
            'reason' => 'Test leave', 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('member', 'admin', 'term', 'target', 'enrollment', 'invoice', 'leave', 'targetSession');
    }

    public function test_assignment_creates_canonical_attendee_without_second_invoice_and_replays_safely(): void
    {
        $f = $this->fixture();
        $service = app(CourseMakeupAssignmentService::class);
        $first = $service->assign($f['leave'], $f['targetSession'], $f['admin']);
        $second = $service->assign($f['leave'], $f['targetSession'], $f['admin']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('course_makeups')->count());
        $this->assertSame(1, DB::table('event_booking_attendees')
            ->where('event_booking_group_id', $first->event_booking_group_id)
            ->where('member_user_id', $f['member']->id)->where('active', true)
            ->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('invoices')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event', 'course.makeup_assigned')->count());
    }

    public function test_assignment_rejects_zero_makeup_capacity_without_partial_booking(): void
    {
        $f = $this->fixture(makeupCapacity: 0);
        $before = DB::table('event_booking_groups')->count();
        try {
            app(CourseMakeupAssignmentService::class)->assign($f['leave'], $f['targetSession'], $f['admin']);
            $this->fail('Zero makeup capacity must reject the assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_course_session_id', $exception->errors());
        }
        $this->assertSame($before, DB::table('event_booking_groups')->count());
        $this->assertSame(0, DB::table('course_makeups')->count());
    }

    public function test_assignment_rejects_full_event_capacity_even_with_makeup_quota(): void
    {
        $f = $this->fixture(makeupCapacity: 2, eventCapacity: 1);
        try {
            app(CourseMakeupAssignmentService::class)->assign($f['leave'], $f['targetSession'], $f['admin']);
            $this->fail('A full target event must not accept a makeup booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_course_session_id', $exception->errors());
        }
        $this->assertSame(0, DB::table('course_makeups')->count());
    }

    public function test_non_admin_cannot_assign_makeup(): void
    {
        $f = $this->fixture();
        try {
            app(CourseMakeupAssignmentService::class)->assign($f['leave'], $f['targetSession'], $f['member']);
            $this->fail('A non-admin must not assign makeup.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admin', $exception->errors());
        }
        $this->assertSame(0, DB::table('course_makeups')->count());
    }
}
