<?php

use App\Filament\Resources\ArticleCategories\Pages\ListArticleCategories;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\RelationManagers\LikesRelationManager;
use App\Models\Article;
use App\Models\ArticleAccessRule;
use App\Models\ArticleCategory;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\ArticleImporter;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// G01
it('creates the S8 tables, columns and permissions', function () {
    foreach (['article_categories', 'articles', 'article_article_category', 'article_access_rules', 'article_likes',
        'article_comments', 'comment_moderation_cases', 'comment_reports'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }
    expect(Schema::hasColumns('users', ['nickname', 'show_in_like_list']))->toBeTrue()
        ->and(Schema::hasColumns('articles', ['created_by', 'author_name', 'cover_path', 'cover_url', 'unpublish_at', 'deleted_at', 'featured_order']))->toBeTrue()
        ->and(Role::findByName(User::ADMIN_ROLE, 'web')->hasPermissionTo('articles.manage'))->toBeTrue()
        ->and(Role::findByName(User::ADMIN_ROLE, 'web')->hasPermissionTo('comments.moderate'))->toBeTrue()
        ->and(User::factory()->create()->show_in_like_list)->toBeFalse();
});

// G02
it('imports Markdown with front matter as a draft and never overwrites a same-title article silently', function () {
    $admin = makeAdmin(['name' => '管理者']);
    $category = articleCategory('教義');
    $md = "---\ntitle: 心法入門\nauthor: 講師甲\nsummary: 簡介\ncategories: [教義, 不存在]\ncover: https://example.com/c.jpg\n---\n# 標題\n\n第一段 **粗體**\n\n<script>alert(1)</script>\n\n- 項目\n";

    $result = app(ArticleImporter::class)->import('a.md', $md, false, $admin);
    $article = $result['article'];

    expect($result['status'])->toBe('created')
        ->and($result['unknown_categories'])->toBe(['不存在'])
        ->and($article->title)->toBe('心法入門')
        ->and($article->status)->toBe(Article::STATUS_DRAFT)
        ->and($article->author_name)->toBe('講師甲')
        ->and($article->created_by)->toBe($admin->id)
        ->and($article->cover_url)->toBe('https://example.com/c.jpg')
        ->and($article->categories->pluck('id')->all())->toBe([$category->id])
        ->and($article->body_html)->toContain('<strong>粗體</strong>')->toContain('<li>')
        ->and($article->body_html)->not->toContain('<script>');

    // Settings an admin changed afterwards must survive an update import.
    $article->update(['summary' => '後台修改的摘要', 'visibility' => Article::VISIBILITY_MEMBERS]);

    $again = app(ArticleImporter::class)->import('b.md', "---\ntitle: 心法入門\n---\n新內文", false, $admin);
    expect($again['status'])->toBe('duplicate')
        ->and($again['existing']->id)->toBe($article->id)
        ->and($article->refresh()->body_html)->not->toContain('新內文');

    $updated = app(ArticleImporter::class)->import('b.md', "---\ntitle: 心法入門\nsummary: 不應套用\n---\n新內文", true, $admin);
    $article->refresh();
    expect($updated['status'])->toBe('updated')
        ->and(Article::query()->count())->toBe(1)
        ->and($article->body_html)->toContain('新內文')
        ->and($article->summary)->toBe('後台修改的摘要')
        ->and($article->visibility)->toBe(Article::VISIBILITY_MEMBERS)
        ->and(AuditEvent::query()->where('event', 'article.imported_update')->count())->toBe(1);
});

it('takes the title from the first heading, then the file name, and defaults the author to the importer', function () {
    $admin = makeAdmin(['name' => '建立者']);

    $a = app(ArticleImporter::class)->import('x.md', "# 由標題而來\n\n內文", false, $admin)['article'];
    $b = app(ArticleImporter::class)->import('檔名標題.md', '只有內文', false, $admin)['article'];

    expect($a->title)->toBe('由標題而來')->and($a->author_name)->toBe('建立者')
        ->and($b->title)->toBe('檔名標題');
});

// G03
it('allows three category levels at most and blocks deleting used categories', function () {
    $l1 = articleCategory('一');
    $l2 = articleCategory('二', $l1);
    $l3 = articleCategory('三', $l2);

    expect($l3->depth)->toBe(3)->and($l3->path())->toBe('一 › 二 › 三');
    expect(fn () => articleCategory('四', $l3))->toThrow(ValidationException::class);
    // Moving a two-level branch under level 2 would make four levels.
    $other = articleCategory('另一');
    articleCategory('另一子', $other);
    expect(fn () => $other->update(['parent_id' => $l2->id]))->toThrow(ValidationException::class);
    // No cycles.
    expect(fn () => $l1->update(['parent_id' => $l3->id]))->toThrow(ValidationException::class);

    expect(fn () => $l2->delete())->toThrow(ValidationException::class);
    liveArticle([], $l3);
    expect(fn () => $l3->delete())->toThrow(ValidationException::class);

    $empty = articleCategory('空');
    $empty->delete();
    expect(ArticleCategory::query()->find($empty->id))->toBeNull();

    $this->actingAs(makeAdmin());
    Livewire::test(ListArticleCategories::class)->callTableAction('deleteCategory', $l2)->assertNotified('無法刪除');
    expect(ArticleCategory::query()->find($l2->id))->not->toBeNull();
});

it('lets a draft have no category but requires one to publish', function () {
    $admin = makeAdmin(['name' => '管理者']);
    $this->actingAs($admin);

    Livewire::test(CreateArticle::class)
        ->fillForm(['title' => '草稿', 'body_html' => '<p>x</p>', 'status' => Article::STATUS_DRAFT])
        ->call('create')
        ->assertHasNoFormErrors();
    $draft = Article::query()->where('title', '草稿')->firstOrFail();
    expect($draft->created_by)->toBe($admin->id)->and($draft->author_name)->toBe('管理者');

    Livewire::test(CreateArticle::class)
        ->fillForm(['title' => '直接發布', 'body_html' => '<p>x</p>', 'status' => Article::STATUS_PUBLISHED, 'categories' => []])
        ->call('create')
        ->assertHasFormErrors(['categories' => 'required']);

    Livewire::test(ListArticles::class)->callTableAction('publishNow', $draft)->assertNotified('發布前請至少選擇一個分類');
    expect($draft->refresh()->status)->toBe(Article::STATUS_DRAFT);

    $draft->categories()->sync([articleCategory()->id]);
    Livewire::test(ListArticles::class)->callTableAction('publishNow', $draft);
    expect($draft->refresh()->status)->toBe(Article::STATUS_PUBLISHED)
        ->and(AuditEvent::query()->where('event', 'article.published')->where('subject_id', $draft->id)->exists())->toBeTrue();
});

it('saves restricted visibility rules and a custom display author', function () {
    $admin = makeAdmin();
    $this->actingAs($admin);
    $category = articleCategory();

    Livewire::test(CreateArticle::class)
        ->fillForm([
            'title' => '限定', 'body_html' => '<p>x</p>', 'author_name' => '對外作者',
            'categories' => [$category->id], 'status' => Article::STATUS_PUBLISHED,
            'visibility' => Article::VISIBILITY_RESTRICTED, 'access_levels' => ['general'], 'access_roles' => ['admin'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->where('title', '限定')->firstOrFail();
    expect($article->author_name)->toBe('對外作者')
        ->and($article->created_by)->toBe($admin->id)
        ->and($article->accessRules->map(fn (ArticleAccessRule $r) => $r->type.':'.$r->value)->sort()->values()->all())
        ->toBe(['level:general', 'role:admin']);

    Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
        ->assertFormSet(['access_roles' => ['admin']])
        ->fillForm(['visibility' => Article::VISIBILITY_PUBLIC])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($article->accessRules()->count())->toBe(0);
});

// G04
it('publishes on schedule, takes down automatically and withdraws', function () {
    $scheduled = liveArticle(['title' => '排程', 'published_at' => now()->addDay(), 'unpublish_at' => now()->addDays(3)]);

    $this->getJson("/api/v1/articles/{$scheduled->id}")->assertNotFound();
    $this->getJson('/api/v1/articles')->assertJsonCount(0, 'data');

    $this->travel(2)->days();
    $this->getJson("/api/v1/articles/{$scheduled->id}")->assertOk();

    $this->travel(2)->days();
    $this->getJson("/api/v1/articles/{$scheduled->id}")->assertNotFound();
    $this->travelBack();

    expect(fn () => liveArticle(['published_at' => now(), 'unpublish_at' => now()->subHour()]))->toThrow(ValidationException::class);

    $article = liveArticle(['title' => '撤回']);
    $this->actingAs(makeAdmin());
    Livewire::test(ListArticles::class)->callTableAction('withdraw', $article);
    expect($article->refresh()->status)->toBe(Article::STATUS_WITHDRAWN);
    $this->getJson("/api/v1/articles/{$article->id}")->assertNotFound();
    expect(AuditEvent::query()->where('event', 'article.withdrawn')->exists())->toBeTrue();

    // Edits of a published article apply at once (no review or versions).
    $live = liveArticle(['title' => '舊標題']);
    $live->update(['title' => '新標題']);
    $this->getJson("/api/v1/articles/{$live->id}")->assertJsonPath('data.title', '新標題');
});

// G06
it('moves articles to the trash, restores them and deletes them permanently', function () {
    $article = liveArticle();
    $this->actingAs(makeAdmin());

    Livewire::test(ListArticles::class)->callTableAction('delete', $article);
    expect($article->refresh()->trashed())->toBeTrue();
    $this->getJson("/api/v1/articles/{$article->id}")->assertNotFound();

    Livewire::test(ListArticles::class)->callTableAction('restore', $article);
    expect($article->refresh()->trashed())->toBeFalse();
    $this->getJson("/api/v1/articles/{$article->id}")->assertOk();

    $article->delete();
    Livewire::test(ListArticles::class)->callTableAction('forceDelete', $article);
    expect(Article::withTrashed()->find($article->id))->toBeNull();
});

it('shows likes with member id and real name to admins only', function () {
    $article = liveArticle();
    $member = articleMember(['name' => '真實姓名甲', 'nickname' => '小甲']);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/like")->assertOk();

    $this->actingAs(makeAdmin());
    Livewire::test(LikesRelationManager::class, ['ownerRecord' => $article, 'pageClass' => EditArticle::class])
        ->assertSee('真實姓名甲')
        ->assertSee((string) $member->id);
});

it('forbids the article admin without the permission', function () {
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('articles.manage');
    $this->actingAs($admin->refresh());

    $this->get('/admin/articles')->assertForbidden();
    $this->get('/admin/article-categories')->assertForbidden();
});
