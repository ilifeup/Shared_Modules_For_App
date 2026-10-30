<?php

namespace Tests\Feature\S9;

use App\Services\Booking\CanonicalDependencyPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Safety regression: incomplete schemas cannot be interpreted as empty tables.
 */
class CanonicalDependencyPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_dependency_tables_fail_closed_without_querying_data(): void
    {
        Schema::shouldReceive('hasTable')->atLeast()->once()->andReturn(false);
        DB::shouldNotReceive('table');

        $report = app(CanonicalDependencyPreflight::class)->inspect();

        $this->assertFalse($report['ready']);
        $this->assertSame(1, $report['blockers']['missing_table_event_bookings']);
        $this->assertSame(1, $report['blockers']['missing_table_event_attendances']);
        $this->assertSame(0, $report['counts']['event_bookings']);
    }

    public function test_missing_canonical_link_columns_fail_closed(): void
    {
        // The real test schema exists, but this facade stub simulates an
        // environment where the additive bridge has not yet been migrated.
        Schema::shouldReceive('hasTable')->andReturn(true);
        Schema::shouldReceive('hasColumn')->andReturnUsing(
            fn (string $table, string $column): bool => ! ($table === 'course_makeups' && $column === 'event_booking_id')
        );
        $report = app(CanonicalDependencyPreflight::class)->inspect();
        $this->assertFalse($report['ready']);
        $this->assertSame(1, $report['blockers']['missing_column_course_makeups_event_booking_id']);
    }

    public function test_preflight_does_not_change_booking_row_count(): void
    {
        $before = DB::table('event_bookings')->count();
        $report = app(CanonicalDependencyPreflight::class)->inspect();
        $this->assertIsBool($report['ready']);
        $this->assertSame($before, DB::table('event_bookings')->count());
    }
}
