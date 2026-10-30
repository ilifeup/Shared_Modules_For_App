<?php

namespace Tests\Feature\S9;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Additive migration contract: no existing booking dependency is dropped.
 * Run on a disposable test database, never on production.
 */
class CanonicalDependencySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_links_exist_alongside_legacy_links(): void
    {
        foreach ([
            'event_waitlist_entries' => 'event_booking_group_id',
            'course_makeups' => 'event_booking_group_id',
            'invoice_booking_items' => 'event_booking_group_id',
        ] as $table => $legacyColumn) {
            $this->assertTrue(Schema::hasColumn($table, $legacyColumn), $table.' legacy link missing');
            $this->assertTrue(Schema::hasColumn($table, 'event_booking_id'), $table.' canonical link missing');
        }

        $this->assertTrue(Schema::hasColumn('event_attendances', 'event_booking_attendee_id'));
        $this->assertTrue(Schema::hasColumn('event_attendances', 'event_booking_id'));
    }

    public function test_invoice_booking_link_supports_multiple_line_items(): void
    {
        // The bridge must use a non-unique index: invoice items are line items,
        // not a one-to-one booking ownership table. This test checks the
        // migration declaration without requiring paid production fixtures.
        $migration = file_get_contents(database_path('migrations/2026_12_02_000100_add_canonical_booking_links_to_dependencies.php'));
        $this->assertStringContainsString("\$table->index('event_booking_id', 'invoice_booking_owner_canonical_index')", $migration);
        $this->assertStringNotContainsString("\$table->unique('event_booking_id', 'invoice_booking_owner_canonical_unique')", $migration);
    }

    public function test_legacy_and_canonical_columns_are_preserved(): void
    {
        // Historical migration compatibility: the additive bridge may not
        // drop either side of a still-active relationship.
        foreach ([
            'event_waitlist_entries',
            'course_makeups',
            'invoice_booking_items',
        ] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'event_booking_group_id'));
            $this->assertTrue(Schema::hasColumn($table, 'event_booking_id'));
        }
    }
}
