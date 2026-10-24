<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * S8 M05 articles (A: management, B: interaction) and the M01 public profile.
 * Backward compatible: existing members get no nickname and a hidden like-list entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        // M01: unified public nickname (used for likes and comments) and privacy.
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 40)->nullable()->unique()->after('display_name');
            $table->boolean('show_in_like_list')->default(false)->after('nickname');
        });

        Schema::create('article_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('article_categories')->restrictOnDelete();
            $table->string('name', 50);
            $table->unsignedTinyInteger('depth')->default(1); // 1..3
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 120)->unique();
            $table->text('summary')->nullable();
            $table->longText('body_html');
            $table->string('cover_path')->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->string('author_name', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');         // draft | published | withdrawn
            $table->timestamp('published_at')->nullable();          // future = scheduled
            $table->timestamp('unpublish_at')->nullable();          // automatic take-down
            $table->string('visibility', 20)->default('public');    // public | members | restricted
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('featured_order')->default(0);
            $table->unsignedInteger('view_count')->default(0);      // de-duplicated views
            $table->unsignedInteger('like_count')->default(0);
            $table->softDeletes();                                  // 垃圾桶
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['is_featured', 'featured_order']);
        });

        Schema::create('article_article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_category_id')->constrained()->restrictOnDelete();
            $table->primary(['article_id', 'article_category_id']);
        });

        // Restricted visibility: a member may read when ANY rule matches (OR).
        Schema::create('article_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);   // level | role
            $table->string('value', 50);  // membership level code or role name
            $table->unique(['article_id', 'type', 'value']);
        });

        Schema::create('article_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['article_id', 'user_id']);
        });

        Schema::create('article_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 20)->default('visible'); // visible | hidden | deleted
            $table->string('hidden_reason', 30)->nullable();   // ai_hide | ai_unsure | ai_unavailable | admin
            $table->timestamps();

            $table->index(['article_id', 'status', 'created_at']);
        });

        // One round of reports per comment; `active` = 1 while open (unique per comment).
        Schema::create('comment_moderation_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_comment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedTinyInteger('active')->nullable()->default(1);
            $table->unsignedSmallInteger('report_count')->default(0);
            $table->string('ai_status', 20)->default('none');   // none | pending | done | failed
            $table->string('ai_decision', 20)->nullable();      // keep | hide | unsure
            $table->string('ai_reason', 500)->nullable();
            $table->timestamp('ai_triggered_at')->nullable();
            $table->timestamp('ai_reviewed_at')->nullable();
            $table->string('resolution', 20)->nullable();       // restored | kept_hidden | deleted | closed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['article_comment_id', 'active']);
            $table->unique(['article_comment_id', 'round']);
        });

        Schema::create('comment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_moderation_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One effective report per member and round.
            $table->unique(['comment_moderation_case_id', 'user_id']);
        });

        foreach (['articles.manage', 'comments.moderate'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('admin', 'web')->givePermissionTo(['articles.manage', 'comments.moderate']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_reports');
        Schema::dropIfExists('comment_moderation_cases');
        Schema::dropIfExists('article_comments');
        Schema::dropIfExists('article_likes');
        Schema::dropIfExists('article_access_rules');
        Schema::dropIfExists('article_article_category');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('article_categories');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nickname']);
            $table->dropColumn(['nickname', 'show_in_like_list']);
        });
    }
};
