<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S4 M04 in-app notification centre: Laravel's standard database-notification
 * table (one row per member and notification; read_at = read status).
 * Email / SMS / push channels are not part of S4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Permission::findOrCreate('notifications.send', 'web');
        Role::findOrCreate('admin', 'web')->givePermissionTo('notifications.send');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
