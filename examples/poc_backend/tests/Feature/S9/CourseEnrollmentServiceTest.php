<?php

namespace Tests\Feature\S9;

use App\Models\CalendarEvent;
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

    public function test_fee_confirmation_books_every_session_atomically_and_is_idempotent(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);

        $events = [s9Event(['booking_capacity' => 2]), s9Event(['booking_capacity' => 2])];
        foreach ($events as $event) {
            DB::table('course_sessions')->insert([
                'course_term_id' => $term, 'calendar_event_id' => $event->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame('confirmed', $service->confirmFee($enrollment['id'], $admin)['status']);
        $this->assertSame('confirmed', $service->confirmFee($enrollment['id'], $admin)['status']);
        $this->assertSame(2, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
        $this->assertNotNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_readiness_reports_available_session_bookings_without_mutating_payment(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);

        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['invalid_session_event_ids']);
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->count());
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_fee_confirmation_rolls_back_all_sessions_if_one_is_unavailable(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);

        $first = s9Event(['booking_capacity' => 2]);
        $second = s9Event(['booking_capacity' => 2, 'booking_enabled' => false]);
        foreach ([$first, $second] as $event) {
            DB::table('course_sessions')->insert([
                'course_term_id' => $term, 'calendar_event_id' => $event->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        try {
            $service->confirmFee($enrollment['id'], $admin);
            $this->fail('Confirmation must fail when a session is unavailable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->count());
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
    }

    public function test_revoked_family_delegation_blocks_fee_confirmation_and_all_session_bookings(): void
    {
        $term = $this->term();
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $manager, $family);
        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('family_memberships')->where('manager_user_id', $manager->id)
            ->where('member_user_id', $family->id)
            ->update(['status' => 'ended', 'ended_at' => now()]);

        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        try {
            $service->confirmFee($enrollment['id'], $admin);
            $this->fail('Revoked delegation must block fee confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('member', $exception->errors());
        }
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $family->id)->count());
    }

    public function test_non_admin_cannot_confirm_fee(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->confirmFee($enrollment['id'], $member);
    }

    public function test_admin_readiness_does_not_mutate_pending_payment(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertFalse($result['ready']);
        $this->assertSame(0, $result['session_count']);
        $this->assertSame('pending_payment', $result['status']);
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_readiness_reports_invalid_session_events_without_confirming_fee(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        $event = CalendarEvent::query()->create([
            'title' => 'Unpublished course session',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHours(2),
            'status' => 'draft',
            'audience' => 'members',
            'booking_enabled' => false,
        ]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertFalse($result['ready']);
        $this->assertSame(1, $result['session_count']);
        $this->assertSame([$event->id], $result['invalid_session_event_ids']);
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_my_course_enrollments_hide_revoked_and_unverified_family_members(): void
    {
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        $other = $this->verified();
        $term = $this->term(3);
        $now = now();

        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = app(CourseEnrollmentService::class);
        $own = $service->enroll($term, $manager, $manager);
        $delegated = $service->enroll($term, $manager, $family);
        $service->enroll($term, $other, $other);

        $url = '/api/v1/me/course-enrollments';
        $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(2, 'data');

        $family->forceFill(['verified_status' => 0])->save();
        $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own['id']);

        $family->forceFill(['verified_status' => 2])->save();
        DB::table('family_memberships')
            ->where('manager_user_id', $manager->id)
            ->where('member_user_id', $family->id)
            ->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotEquals($delegated['id'], $response->json('data.0.id'));
    }

    public function test_delegated_family_history_is_limited_to_two_months_but_own_history_is_not(): void
    {
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        $term = $this->term(3);
        $now = now();

        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = app(CourseEnrollmentService::class);
        $own = $service->enroll($term, $manager, $manager);
        $familyEnrollment = $service->enroll($term, $manager, $family);

        DB::table('course_enrollments')->whereIn('id', [$own['id'], $familyEnrollment['id']])
            ->update(['created_at' => now()->subMonths(3)]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own['id']);
    }

    public function test_non_admin_cannot_read_fee_confirmation_readiness(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->confirmationReadiness($enrollment['id'], $member);
    }
}
