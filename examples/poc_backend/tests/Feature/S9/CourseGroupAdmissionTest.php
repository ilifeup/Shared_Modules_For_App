<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Course\CourseGroupAdmission;
use App\Services\Course\CourseGroupReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CourseGroupAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function group(int $capacity = 1): int
    {
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => '基礎班', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('course_groups')->insertGetId([
            'course_category_id' => $categoryId, 'name' => '基礎班第一梯',
            'capacity' => $capacity, 'waitlist_capacity' => 5,
            'expected_event_count' => 12, 'price_amount' => '1200.00',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function member(): User
    {
        return User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    }

    private function ready(): void
    {
        $this->mock(CourseGroupReadiness::class, function ($mock): void {
            $mock->shouldReceive('inspect')->andReturn(['ready' => true]);
        });
    }

    public function test_last_seat_then_waitlist_and_idempotent_replay(): void
    {
        $this->ready();
        $group = $this->group();
        $first = $this->member();
        $second = $this->member();
        $calls = 0;
        $create = function () use (&$calls): void { $calls++; };
        $service = app(CourseGroupAdmission::class);

        $one = $service->reserve($group, $first, $first, '00000000-0000-4000-8000-000000000001', $create);
        $two = $service->reserve($group, $second, $second, '00000000-0000-4000-8000-000000000002', $create);
        $replay = $service->reserve($group, $first, $first, '00000000-0000-4000-8000-000000000001', $create);

        $this->assertSame('pending_payment', $one['status']);
        $this->assertSame('waitlisted', $two['status']);
        $this->assertSame(1, $calls);
        $this->assertSame($one['id'], $replay['id']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, DB::table('course_group_members')->where('status', 'pending_payment')->count());
        $this->assertSame(1, DB::table('course_group_members')->where('status', 'waitlisted')->count());
    }

    public function test_failed_event_booking_rolls_back_seat_and_request_key(): void
    {
        $this->ready();
        $group = $this->group();
        $member = $this->member();

        try {
            app(CourseGroupAdmission::class)->reserve(
                $group, $member, $member, '00000000-0000-4000-8000-000000000003',
                static function (): void { throw new \RuntimeException('Simulated booking failure'); },
            );
            $this->fail('A failed booking must not commit its group seat.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated booking failure', $e->getMessage());
        }

        $this->assertSame(0, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }
}
