<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CourseEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function term(int $capacity = 1, int $waitlist = 5): int
    {
        return DB::table('course_terms')->insertGetId([
            'title' => 'MVP test term',
            'enrollment_capacity' => $capacity,
            'waitlist_capacity' => $waitlist,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function verified(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['verified_status' => 2, 'is_active' => true, 'FamilyUser' => false])->save();

        return $user;
    }

    public function test_term_capacity_and_fifo_waitlist_are_independent(): void
    {
        $term = $this->term();
        $service = app(CourseEnrollmentService::class);
        $first = $this->verified();
        $this->assertSame('pending_payment', $service->enroll($term, $first, $first)['status']);

        for ($i = 1; $i <= 5; $i++) {
            $member = $this->verified();
            $result = $service->enroll($term, $member, $member);
            $this->assertSame('waitlisted', $result['status']);
            $this->assertSame($i, $result['waitlist_sequence']);
        }

        $extra = $this->verified();
        $this->expectException(ValidationException::class);
        $service->enroll($term, $extra, $extra);
    }

    public function test_zero_waitlist_capacity_blocks_additional_enrollment(): void
    {
        $term = $this->term(1, 0);
        $service = app(CourseEnrollmentService::class);
        $member = $this->verified();
        $service->enroll($term, $member, $member);
        $second = $this->verified();
        $this->expectException(ValidationException::class);
        $service->enroll($term, $second, $second);
    }

    public function test_unverified_member_cannot_enroll(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $member->forceFill(['verified_status' => 1])->save();
        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->enroll($term, $member, $member);
    }

    public function test_fee_confirmation_is_blocked_until_atomic_session_booking_exists(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        try {
            $service->confirmFee($enrollment['id'], $admin);
            $this->fail('Premature fee confirmation must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('enrollment', $exception->errors());
        }

        $row = DB::table('course_enrollments')->find($enrollment['id']);
        $this->assertSame('pending_payment', $row->status);
        $this->assertNull($row->fee_confirmed_at);
    }

    public function test_non_admin_cannot_confirm_fee(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->confirmFee($enrollment['id'], $member);
    }
}
