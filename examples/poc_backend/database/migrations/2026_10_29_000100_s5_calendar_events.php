<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S5 M09 calendar events (separate from notices). Times are stored in UTC.
 * Cancelled events keep their row and status; the id stays stable for S6 bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('draft');      // draft | published | cancelled
            $table->string('audience', 20)->default('members');  // public | members
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['status', 'ends_at']);
        });

        Permission::findOrCreate('events.manage', 'web');
        Role::findOrCreate('admin', 'web')->givePermissionTo('events.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
