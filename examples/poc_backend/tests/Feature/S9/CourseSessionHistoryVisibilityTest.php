<?php

namespace Tests\Feature\S9;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseSessionHistoryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function createEnrollment(User $member, User $booker, int $monthsAgo): int
    {
        $termId = DB::table('course_terms')->insertGetId([
            'title' => 'History visibility',
            'price_amount' => 1,
            'enrollment_capacity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = s9Event(['starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(2)]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $termId,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('course_enrollments')->insertGetId([
            'course_term_id' => $termId,
            'user_id' => $member->id,
            'booker_user_id' => $booker->id,
            'status' => 'confirmed',
            'created_at' => now()->subMonths($monthsAgo),
            'updated_at' => now(),
        ]);
    }

    public function test_original_booker_cannot_read_family_sessions_older_than_two_months(): void
    {
        $booker = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $id = $this->createEnrollment($member, $booker, 3);

        $this->actingAs($booker)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertNotFound();
        $this->actingAs($booker)->getJson('/api/v1/me/course-enrollments')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($member)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($member)->getJson('/api/v1/me/course-enrollments')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_original_booker_can_read_recent_family_sessions_without_mutation_rights(): void
    {
        $booker = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $id = $this->createEnrollment($member, $booker, 1);

        $this->actingAs($booker)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($booker)->getJson('/api/v1/me/course-enrollments')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_course_session_attendance_distinguishes_leave_absent_and_upcoming(): void
    {
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $id = $this->createEnrollment($member, $member, 0);
        $session = DB::table('course_sessions')->first();
        $eventId = $session->calendar_event_id;
        $url = "/api/v1/me/course-enrollments/{$id}/sessions";

        $this->actingAs($member)->getJson($url)
            ->assertOk()->assertJsonPath('data.0.attendance_status', 'upcoming');

        DB::table('calendar_events')->where('id', $eventId)->update([
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(),
        ]);
        $this->actingAs($member)->getJson($url)
            ->assertOk()->assertJsonPath('data.0.attendance_status', 'absent');

        DB::table('course_session_leaves')->insert([
            'course_enrollment_id' => $id, 'course_session_id' => $session->id,
            'reason' => '事先請假', 'requested_at' => now()->subDays(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($member)->getJson($url)
            ->assertOk()->assertJsonPath('data.0.attendance_status', 'leave');
    }

    public function test_unrelated_user_cannot_read_enrollment_sessions(): void
    {
        $booker = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $stranger = User::factory()->create(['is_active' => true, 'verified_status' => 2]);
        $id = $this->createEnrollment($member, $booker, 1);

        $this->actingAs($stranger)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertNotFound();
    }
}
