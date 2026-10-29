<?php

namespace Tests\Feature\S9;

use App\Models\User;
use App\Services\Course\CourseGroupReadiness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseGroupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_booking_schema_has_all_required_tables(): void
    {
        foreach (['course_categories', 'course_groups', 'course_group_events',
            'course_group_members', 'invoice_booking_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_open_group_without_all_twelve_events_is_not_ready(): void
    {
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => '基礎班', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = DB::table('course_groups')->insertGetId([
            'course_category_id' => $categoryId, 'name' => '基礎班第一梯',
            'capacity' => 5, 'waitlist_capacity' => 5, 'expected_event_count' => 12,
            'price_amount' => '1200.00', 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CourseGroupReadiness::class)->inspect($groupId);

        $this->assertFalse($result['ready']);
        $this->assertSame(12, $result['expected_event_count']);
        $this->assertSame(0, $result['configured_event_count']);
        $this->assertContains('尚未完成預定全部課堂。', $result['errors']);
    }

    public function test_group_rejects_nonpositive_payment_hold_and_missing_lesson_positions(): void
    {
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => '付款期限測試', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = DB::table('course_groups')->insertGetId([
            'course_category_id' => $categoryId, 'name' => '基礎班測試',
            'capacity' => 5, 'waitlist_capacity' => 5, 'expected_event_count' => 12,
            'price_amount' => '1200.00', 'payment_hold_hours' => 0,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(CourseGroupReadiness::class)->inspect($groupId);

        $this->assertFalse($result['ready']);
        $this->assertContains('付款保留時數必須大於零。', $result['errors']);
        $this->assertContains('課堂順序必須從 1 起連續排列。', $result['errors']);
    }

    public function test_one_member_cannot_hold_two_places_in_same_group(): void
    {
        $member = User::factory()->create();
        $categoryId = DB::table('course_categories')->insertGetId([
            'name' => '基礎班', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = DB::table('course_groups')->insertGetId([
            'course_category_id' => $categoryId, 'name' => '基礎班第一梯',
            'capacity' => 5, 'waitlist_capacity' => 5, 'expected_event_count' => 12,
            'price_amount' => '1200.00', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entry = [
            'course_group_id' => $groupId, 'member_user_id' => $member->id,
            'booker_user_id' => $member->id, 'status' => 'waitlisted',
            'request_key' => 'request-one', 'waitlist_sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('course_group_members')->insert($entry);

        try {
            DB::table('course_group_members')->insert(array_merge($entry, [
                'request_key' => 'request-two', 'waitlist_sequence' => 2,
            ]));
            $this->fail('The database must reject duplicate member places.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame(1, DB::table('course_group_members')
                ->where('course_group_id', $groupId)->count());
        }
    }
}
