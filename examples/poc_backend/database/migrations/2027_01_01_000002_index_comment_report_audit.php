<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            // Keep a dedicated FK-supporting index so rollback can safely drop the composite one.
            $table->index('actor_id', 'audit_report_actor_fk_idx');
            $table->index(['actor_id', 'event', 'created_at'], 'audit_report_actor_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_report_actor_event_time_idx');
            $table->dropIndex('audit_report_actor_fk_idx');
        });
    }
};
