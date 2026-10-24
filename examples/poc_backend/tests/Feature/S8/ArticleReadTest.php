<?php

use App\Models\Article;
use App\Models\ArticleAccessRule;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\MembershipService;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function restrictedArticle(array $rules): Article
{
    $article = liveArticle(['title' => '限定文章', 'visibility' => Article::VISIBILITY_RESTRICTED]);
    foreach ($rules as [$type, $value]) {
        $article->accessRules()->create(['type' => $type, 'value' => $value]);
    }

    return $article;
}

// G05
it('enforces public, member and level-or-role read permission on the server', function () {
    $public = liveArticle(['title' => '公開文章']);
    $members = liveArticle(['title' => '會員文章', 'visibility' => Article::VISIBILITY_MEMBERS]);
    $restricted = restrictedArticle([[ArticleAccessRule::TYPE_LEVEL, 'vip'], [ArticleAccessRule::TYPE_ROLE, 'teacher']]);

    $member = User::factory()->create();
    $unverified = User::factory()->unverified()->create();
    $vip = User::factory()->create();
    app(MembershipService::class)->assign($vip, MembershipLevel::query()->where('code', 'vip')->firstOrFail(), now()->subDay(), null, null, makeAdmin());
    $teacher = User::factory()->create();
    $teacher->assignRole(Role::findOrCreate('teacher', 'web'));

    // Visitors
    $this->getJson("/api/v1/articles/{$public->id}")->assertOk()->assertJsonPath('data.body_html', '<p>內文</p>');
    $this->getJson("/api/v1/articles/{$members->id}")->assertStatus(401)->assertJsonPath('error.code', 'ARTICLE_LOGIN_REQUIRED');
    $this->getJson("/api/v1/articles/{$restricted->id}")->assertStatus(401);
    expect(collect($this->getJson('/api/v1/articles')->json('data'))->pluck('title')->all())->toBe(['公開文章']);

    // Unverified account
    $this->actingAs($unverified, 'sanctum')->getJson("/api/v1/articles/{$members->id}")
        ->assertStatus(403)->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');

    // Ordinary member
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$members->id}")->assertOk();
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$restricted->id}")
        ->assertStatus(403)->assertJsonPath('error.code', 'ARTICLE_FORBIDDEN');
    expect(collect($this->actingAs($member, 'sanctum')->getJson('/api/v1/articles')->json('data'))->pluck('title')->sort()->values()->all())
        ->toBe(['公開文章', '會員文章']);
    // Interactions and lists of a forbidden article are refused too.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$restricted->id}/like")->assertStatus(403);
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$restricted->id}/comments")->assertStatus(403);
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$restricted->id}/likes")->assertStatus(403);

    // Level OR role
    $this->actingAs($vip, 'sanctum')->getJson("/api/v1/articles/{$restricted->id}")->assertOk();
    $this->actingAs($teacher, 'sanctum')->getJson("/api/v1/articles/{$restricted->id}")->assertOk();
    expect(collect($this->actingAs($teacher, 'sanctum')->getJson('/api/v1/articles')->json('data'))->pluck('title'))->toContain('限定文章');
});

it('protects uploaded covers with the article permission', function () {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('c.jpg')->store('article-covers', 'local');
    $article = liveArticle(['visibility' => Article::VISIBILITY_MEMBERS, 'cover_path' => $path]);

    $this->getJson("/api/v1/articles/{$article->id}/cover")->assertStatus(401);
    $this->actingAs(User::factory()->create(), 'sanctum')->get("/api/v1/articles/{$article->id}/cover")->assertOk();
});

// B02
it('shares links without bypassing read permission', function () {
    $public = liveArticle(['title' => '公開分享', 'body_html' => '<p>公開內文</p>']);
    $members = liveArticle(['title' => '會員秘密標題', 'body_html' => '<p>會員秘密內文</p>', 'visibility' => Article::VISIBILITY_MEMBERS]);
    $draft = liveArticle(['status' => Article::STATUS_DRAFT]);

    $share = $this->getJson("/api/v1/articles/{$public->id}")->json('data.share_url');
    expect($share)->toEndWith('/articles/'.$public->slug);

    $this->get('/articles/'.$public->slug)->assertOk()->assertSee('公開內文');
    $this->get('/articles/'.$members->slug)->assertOk()
        ->assertSee('僅限會員閱讀')
        ->assertDontSee('會員秘密標題')
        ->assertDontSee('會員秘密內文');
    $this->get('/articles/'.$draft->slug)->assertNotFound();
});

// G07
it('lists featured articles in manual order and sorts by time or popularity', function () {
    $old = liveArticle(['title' => '舊', 'published_at' => now()->subDays(3)]);
    $mid = liveArticle(['title' => '中', 'published_at' => now()->subDays(2)]);
    $new = liveArticle(['title' => '新', 'published_at' => now()->subDay()]);
    $old->forceFill(['view_count' => 25])->save();
    $mid->forceFill(['like_count' => 2])->save();
    $new->forceFill(['view_count' => 5])->save();
    $mid->update(['is_featured' => true, 'featured_order' => 2]);
    $new->update(['is_featured' => true, 'featured_order' => 1]);

    expect(collect($this->getJson('/api/v1/articles/featured')->json('data'))->pluck('title')->all())->toBe(['新', '中'])
        ->and(collect($this->getJson('/api/v1/articles')->json('data'))->pluck('title')->all())->toBe(['新', '中', '舊'])
        // 25 > 2×10 > 5
        ->and(collect($this->getJson('/api/v1/articles?sort=popular')->json('data'))->pluck('title')->all())->toBe(['舊', '中', '新']);

    app(Settings::class)->put(['article_like_weight' => 20]);
    // 2×20 = 40 > 25
    expect(collect($this->getJson('/api/v1/articles?sort=popular')->json('data'))->pluck('title')->all())->toBe(['中', '舊', '新']);
    expect(fn () => app(Settings::class)->put(['article_like_weight' => -1]))->toThrow(InvalidArgumentException::class);
});

it('filters by category and returns the category tree', function () {
    $root = articleCategory('根');
    $child = articleCategory('子', $root);
    liveArticle(['title' => '在子分類'], $child);
    liveArticle(['title' => '別處']);

    expect(collect($this->getJson("/api/v1/articles?category={$child->id}")->json('data'))->pluck('title')->all())->toBe(['在子分類']);
    $tree = collect($this->getJson('/api/v1/article-categories')->assertOk()->json('data'))->firstWhere('name', '根');
    expect($tree['children'][0]['name'])->toBe('子');
});

it('counts a view once per viewer within the de-duplication window', function () {
    $article = liveArticle();
    $member = User::factory()->create();

    $this->getJson("/api/v1/articles/{$article->id}");
    $this->getJson("/api/v1/articles/{$article->id}");
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$article->id}");
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$article->id}");
    expect($article->refresh()->view_count)->toBe(2);

    $this->travel(31)->minutes();
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/articles/{$article->id}");
    expect($article->refresh()->view_count)->toBe(3);
});

it('returns native blocks for the App renderer', function () {
    $article = liveArticle(['body_html' => '<h2>小標</h2><p>段落</p><ul><li>一</li></ul><blockquote><p>引言</p></blockquote>']);

    $blocks = $this->getJson("/api/v1/articles/{$article->id}")->json('data.blocks');
    expect(collect($blocks)->pluck('type')->all())->toBe(['heading', 'paragraph', 'list_item', 'quote']);
});
