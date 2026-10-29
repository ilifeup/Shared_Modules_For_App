<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Course\CourseGroupAdmission;
use App\Support\Api\ApiException;
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

        $groupId = DB::table('course_groups')->insertGetId([
            'course_category_id' => $categoryId, 'name' => '基礎班第一梯',
            'capacity' => $capacity, 'waitlist_capacity' => 5,
            'expected_event_count' => 1, 'price_amount' => '1200.00',
            'payment_hold_hours' => 24,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = s9Event([
            'booking_capacity' => max(2, $capacity),
            'booking_enabled' => true,
            'status' => 'published',
            'starts_at' => now()->addDays(7),
        ]);
        DB::table('course_group_events')->insert([
            'course_group_id' => $groupId,
            'calendar_event_id' => $event->id,
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $groupId;
    }

    private function member(): User
    {
        return User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    }

    public function test_malformed_request_key_is_rejected_before_writing(): void
    {
        $group = $this->group();
        $member = $this->member();

        try {
            app(CourseGroupAdmission::class)->reserve(
                $group, $member, $member, '00000000-0000-0000-0000-00000000000Z',
                static function (): void {},
            );
            $this->fail('Malformed request keys must be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('INVALID_REQUEST_ID', $exception->errorCode);
        }

        $this->assertSame(0, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }

    public function test_last_seat_then_waitlist_and_idempotent_replay(): void
    {
        $group = $this->group();
        $first = $this->member();
        $second = $this->member();
        $calls = 0;
        $create = function () use (&$calls): void {
            $calls++;
        };
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

    public function test_replay_is_forbidden_after_member_verification_revoked(): void
    {
        $group = $this->group();
        $member = $this->member();
        $requestId = '00000000-0000-4000-8000-000000000004';
        $service = app(CourseGroupAdmission::class);
        $service->reserve($group, $member, $member, $requestId, static function (): void {});
        $member->forceFill(['verified_status' => 0])->save();

        try {
            $service->reserve($group, $member, $member, $requestId, static function (): void {
                throw new \RuntimeException('Must never invoke callback on replay.');
            });
            $this->fail('Revoked members must not replay protected enrollment details.');
        } catch (ApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('MEMBER_NOT_ELIGIBLE', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }

    public function test_waitlist_is_bounded_and_sequence_is_monotonic(): void
    {
        $group = $this->group();
        DB::table('course_groups')->where('id', $group)->update(['waitlist_capacity' => 1]);
        $members = [$this->member(), $this->member(), $this->member()];
        $service = app(CourseGroupAdmission::class);
        $callback = static function (): void {};

        $service->reserve($group, $members[0], $members[0], '00000000-0000-4000-8000-000000000011', $callback);
        $waiting = $service->reserve($group, $members[1], $members[1], '00000000-0000-4000-8000-000000000012', $callback);
        $this->assertSame('waitlisted', $waiting['status']);
        $this->assertSame(1, (int) DB::table('course_group_members')->where('id', $waiting['id'])->value('waitlist_sequence'));
        $this->assertSame(2, (int) DB::table('course_groups')->where('id', $group)->value('next_waitlist_sequence'));

        try {
            $service->reserve($group, $members[2], $members[2], '00000000-0000-4000-8000-000000000013', $callback);
            $this->fail('Waitlist must reject a sixth candidate once full.');
        } catch (ApiException $exception) {
            $this->assertSame('WAITLIST_FULL', $exception->errorCode);
        }
        $this->assertSame(2, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }

    public function test_same_request_key_for_different_member_is_conflict(): void
    {
        $group = $this->group();
        $first = $this->member();
        $second = $this->member();
        $key = '00000000-0000-4000-8000-000000000014';
        $service = app(CourseGroupAdmission::class);
        $callback = static function (): void {};
        $service->reserve($group, $first, $first, $key, $callback);

        try {
            $service->reserve($group, $second, $second, $key, $callback);
            $this->fail('Request keys must not be reused across members.');
        } catch (ApiException $exception) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
        $this->assertSame(1, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }

    public function test_failed_event_booking_rolls_back_seat_and_request_key(): void
    {
        $group = $this->group();
        $member = $this->member();

        try {
            app(CourseGroupAdmission::class)->reserve(
                $group, $member, $member, '00000000-0000-4000-8000-000000000003',
                static function (): void {
                    throw new \RuntimeException('Simulated booking failure');
                },
            );
            $this->fail('A failed booking must not commit its group seat.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated booking failure', $e->getMessage());
        }

        $this->assertSame(0, DB::table('course_group_members')->where('course_group_id', $group)->count());
    }
}
