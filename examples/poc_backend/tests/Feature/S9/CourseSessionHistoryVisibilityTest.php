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
        $event = s9Event(['starts_at' => now()->addDays(10)]);
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
        $booker = User::factory()->create();
        $member = User::factory()->create();
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
        $booker = User::factory()->create();
        $member = User::factory()->create();
        $id = $this->createEnrollment($member, $booker, 1);

        $this->actingAs($booker)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($booker)->getJson('/api/v1/me/course-enrollments')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_unrelated_user_cannot_read_enrollment_sessions(): void
    {
        $booker = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $id = $this->createEnrollment($member, $booker, 1);

        $this->actingAs($stranger)->getJson("/api/v1/me/course-enrollments/{$id}/sessions")
            ->assertNotFound();
    }
}
