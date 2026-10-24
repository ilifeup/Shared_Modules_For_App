<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S3.2 (backward compatible, existing members keep their data):
 * - A03: avatar file path on users (file stored on the private avatar disk)
 * - A04: membership levels (一般 / VIP) and their assignment history.
 *   Levels are member benefits only; they are separate from Spatie roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('phone_verified_at');
        });

        Schema::create('membership_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();          // general | vip (fixed, used by code)
            $table->string('name', 50);                     // editable in Filament
            $table->text('benefits')->nullable();           // editable in Filament
            $table->unsignedSmallInteger('rank')->default(0);
            $table->boolean('is_default')->default(false);  // level of members without an active assignment
            $table->timestamps();
        });

        // History is kept: expired and revoked assignments stay in the table.
        Schema::create('member_level_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_level_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();       // null = no end date
            $table->string('reason', 255)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at', 'starts_at']);
        });

        $now = now();
        DB::table('membership_levels')->insert([
            ['code' => 'general', 'name' => '一般會員', 'benefits' => '可使用 App 會員功能。', 'rank' => 0, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'vip', 'name' => 'VIP 會員', 'benefits' => null, 'rank' => 10, 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (['members.manage', 'levels.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('admin', 'web')->givePermissionTo(['members.manage', 'levels.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('member_level_assignments');
        Schema::dropIfExists('membership_levels');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
