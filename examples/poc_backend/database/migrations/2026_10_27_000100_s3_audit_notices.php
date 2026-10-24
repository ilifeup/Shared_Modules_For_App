<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S3.3 (backward compatible): notice categories, pinning, publish window,
 * withdrawn state, audience and per-member read status. Existing S2 notices keep
 * working: no category, not pinned, no end time, audience "members".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notice_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('notices', function (Blueprint $table) {
            $table->foreignId('notice_category_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_pinned')->default(false)->after('status');
            $table->string('audience', 20)->default('members')->after('is_pinned'); // public | members
            $table->timestamp('publish_ends_at')->nullable()->after('published_at');

            $table->index(['status', 'is_pinned', 'published_at']);
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();

            // One row per member and notice: repeated submissions never duplicate.
            $table->unique(['notice_id', 'user_id']);
            $table->index('user_id');
        });

        foreach (['notices.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('admin', 'web')->givePermissionTo(['notices.manage', 'audit.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');

        Schema::table('notices', function (Blueprint $table) {
            $table->dropIndex(['status', 'is_pinned', 'published_at']);
            $table->dropConstrainedForeignId('notice_category_id');
            $table->dropColumn(['is_pinned', 'audience', 'publish_ends_at']);
        });

        Schema::dropIfExists('notice_categories');
    }
};
