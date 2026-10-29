<?php

namespace Tests\Feature\S9;

use App\Models\CourseMakeup;
use App\Models\EventBookingGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseMakeupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_makeup_references_the_canonical_booking_group(): void
    {
        $this->assertTrue(Schema::hasColumn('course_makeups', 'event_booking_group_id'));
        $this->assertFalse(Schema::hasColumn('course_makeups', 'event_booking_id'));

        $relation = (new CourseMakeup)->booking();
        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(EventBookingGroup::class, $relation->getRelated());
        $this->assertSame('event_booking_group_id', $relation->getForeignKeyName());

        // MySQL must enforce the canonical foreign key, not only Eloquent.
        $foreignKeys = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'course_makeups')
            ->where('COLUMN_NAME', 'event_booking_group_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get();

        $this->assertCount(1, $foreignKeys);
        $this->assertSame('event_booking_groups', $foreignKeys->first()->REFERENCED_TABLE_NAME);
        $this->assertSame('id', $foreignKeys->first()->REFERENCED_COLUMN_NAME);
    }
}
