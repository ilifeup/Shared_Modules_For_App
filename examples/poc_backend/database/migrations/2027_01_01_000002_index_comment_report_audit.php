<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may use the composite index for the actor foreign key. Retain a
        // dedicated index across rollbacks; do not create it twice on re-migrate.
        $indexNames = array_column(Schema::getIndexes('audit_events'), 'name');
        if (! in_array('audit_report_actor_fk_idx', $indexNames, true)) {
            Schema::table('audit_events', function (Blueprint $table): void {
                $table->index('actor_id', 'audit_report_actor_fk_idx');
            });
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['actor_id', 'event', 'created_at'], 'audit_report_actor_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_report_actor_event_time_idx');
        });
    }
};
