<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S3.1 (backward compatible): OTP purpose, audit details/result, admin permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_otps', function (Blueprint $table) {
            // verify | password_reset | phone_change. Existing rows are phone verification codes.
            $table->string('purpose', 20)->default('verify')->after('phone_e164');
            $table->index(['user_id', 'purpose', 'status']);
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('result', 20)->nullable()->after('subject_id');
            // Masked, non-secret context only (e.g. old/new setting values).
            $table->json('details')->nullable()->after('result');
        });

        foreach (['settings.manage', 'audit.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('admin', 'web')->givePermissionTo(['settings.manage', 'audit.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn(['result', 'details']);
        });

        Schema::table('phone_otps', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'purpose', 'status']);
            $table->dropColumn('purpose');
        });
    }
};
