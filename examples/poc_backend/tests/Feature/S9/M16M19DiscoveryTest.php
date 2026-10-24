<?php

use App\Models\Article;
use App\Models\Bookmark;
use App\Models\CalendarEvent;
use App\Models\Tag;
use App\Models\User;
use App\Services\Content\SearchService;
use App\Services\Content\TagCsv;
use App\Services\Content\TagService;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function tagNamed(string $name, array $attributes = []): Tag
{
    return app(TagService::class)->create($name, $attributes['color'] ?? null, $attributes['sort'] ?? 0, makeAdmin());
}

// ---- M16 tags ----

// D05 / D06 / D07 — names unique after normalisation; colours automatic unless chosen.
it('normalises tag names, refuses duplicates and gives automatic colours', function () {
    $tag = tagNamed('  Ｔａｉｃｈｉ   Class ');
    expect($tag->normalized_name)->toBe('taichi class')
        ->and($tag->displayColor())->toBeIn(Tag::PALETTE);

    expect(fn () => tagNamed('taichi CLASS'))->toThrow(ValidationException::class);
    expect(fn () => tagNamed('紅色', ['color' => 'red']))->toThrow(ValidationException::class);
    expect(tagNamed('藍色', ['color' => '#1E88E5'])->displayColor())->toBe('#1E88E5');
});

// D22 — at most 10 tags per content item.
it('limits a content item to ten tags', function () {
    $article = liveArticle();
    $tags = collect(range(1, 11))->map(fn (int $i) => tagNamed('標籤'.$i));
    $service = app(TagService::class);

    $service->sync('article', $article->id, $tags->take(10)->pluck('id')->all());
    expect($service->tagsOf('article', $article->id))->toHaveCount(10);
    expect(fn () => $service->sync('article', $article->id, $tags->pluck('id')->all()))->toThrow(ApiException::class, '最多 10 個標籤');
});

// D10 / D09 / D28 — merge moves relations without duplicates; delete keeps the content.
it('merges tags without duplicate relations and deletes tags but never content', function () {
    $service = app(TagService::class);
    [$a, $b] = [liveArticle(), liveArticle()];
    [$source, $target] = [tagNamed('太極'), tagNamed('太極拳')];
    $service->sync('article', $a->id, [$source->id, $target->id]);
    $service->sync('article', $b->id, [$source->id]);

    expect($service->merge($source, $target, makeAdmin()))->toBe(1)
        ->and(Tag::query()->find($source->id))->toBeNull()
        ->and(DB::table('taggables')->where('tag_id', $target->id)->count())->toBe(2);

    expect($service->deletePreview([$target->id]))->toMatchArray(['tags' => 1, 'relations' => 2]);
    $service->delete([$target->id], makeAdmin());
    expect(DB::table('taggables')->count())->toBe(0)->and(Article::query()->count())->toBe(2);
});

// D12 / D13 / D14 / D17 — public tags count public content only; inactive or unused tags hidden.
it('lists only active tags with public content and counts public items only', function () {
    $service = app(TagService::class);
    [$used, $membersOnly, $off] = [tagNamed('公開', ['sort' => 2]), tagNamed('會員', ['sort' => 1]), tagNamed('停用')];
    $public = liveArticle();
    $members = liveArticle(['visibility' => Article::VISIBILITY_MEMBERS]);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC]);
    $service->sync('article', $public->id, [$used->id, $off->id]);
    $service->sync('article', $members->id, [$used->id, $membersOnly->id]);
    $service->sync('event', $event->id, [$used->id]);
    $service->setActive([$off->id], false, makeAdmin());

    $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', '公開')->assertJsonPath('data.0.count', 2);

    // Tags of a type the App turned off are not counted.
    app(Settings::class)->put(['content_tag_types' => 'article']);
    $this->getJson('/api/v1/tags?q=公')->assertJsonPath('data.0.count', 1);
});

// D20 / D29 — CSV import skips existing names; export neutralises formulas.
it('imports tags from CSV skipping duplicates and exports formula-safe CSV', function () {
    tagNamed('既有');
    $result = app(TagCsv::class)->import("\xEF\xBB\xBFname,color,sort,is_active\n既有,,1,1\n新標籤,#43A047,2,1\n停用者,,3,0\n,,,\n", makeAdmin());

    expect($result['created'])->toBe(['新標籤', '停用者'])
        ->and($result['skipped'])->toHaveCount(1)
        ->and(Tag::query()->where('name', '停用者')->value('is_active'))->toBeFalsy();

    tagNamed('=SUM(A1)');
    expect(app(TagCsv::class)->export())->toContain("'=SUM(A1)");
});

// ---- M19 search ----

// D07 / D08 / D09 — AND keywords, visibility from the content rules, tag AND filter.
it('searches with AND keywords and never shows content the viewer cannot open', function () {
    $hit = liveArticle(['title' => '靜坐 入門', 'summary' => '基礎', 'published_at' => now()->subDays(2)]);
    liveArticle(['title' => '靜坐 進階']);                                             // only one keyword
    $secret = liveArticle(['title' => '靜坐 入門 會員', 'visibility' => Article::VISIBILITY_MEMBERS]);
    liveArticle(['title' => '靜坐 入門 草稿', 'status' => Article::STATUS_DRAFT]);
    $event = s9Event(['title' => '靜坐入門 活動', 'audience' => CalendarEvent::AUDIENCE_PUBLIC, 'location' => '台北道場']);

    $visitor = $this->getJson('/api/v1/search?q='.urlencode('靜坐 入門'))->assertOk();
    expect(collect($visitor->json('data'))->pluck('content_id')->all())->toEqualCanonicalizing([(string) $hit->id, (string) $event->id])
        ->and($visitor->json('meta.per_page'))->toBe(20);

    $member = User::factory()->create();
    $mine = $this->actingAs($member, 'sanctum')->getJson('/api/v1/search?q='.urlencode('靜坐 入門').'&type=article')->assertOk();
    expect(collect($mine->json('data'))->pluck('content_id')->all())->toContain((string) $secret->id)
        ->and($mine->json('data.0.bookmarked'))->toBeFalse();

    // Location filter limits to events; tag filter is AND.
    $this->getJson('/api/v1/search?location='.urlencode('台北'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.content_type', 'event');
    [$t1, $t2] = [tagNamed('甲'), tagNamed('乙')];
    app(TagService::class)->sync('article', $hit->id, [$t1->id, $t2->id]);
    app(TagService::class)->sync('event', $event->id, [$t1->id]);
    $this->getJson('/api/v1/search?tags[]='.$t1->id.'&tags[]='.$t2->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.content_id', (string) $hit->id);
});

// D15 / D16 — 20 per page, stable order, sorting combined with filters.
it('pages search results by 20 without duplicates and sorts by date', function () {
    foreach (range(1, 25) as $i) {
        liveArticle(['title' => '共同 '.$i, 'published_at' => now()->subMinutes($i)]);
    }

    $first = $this->getJson('/api/v1/search?q=共同&sort=newest')->assertOk();
    $second = $this->getJson('/api/v1/search?q=共同&sort=newest&page=2')->assertOk();
    $ids = collect($first->json('data'))->merge($second->json('data'))->pluck('content_id');

    expect($first->json('data'))->toHaveCount(20)
        ->and($second->json('data'))->toHaveCount(5)
        ->and($first->json('meta.last_page'))->toBe(2)
        ->and($ids->unique())->toHaveCount(25)
        ->and($first->json('data.0.title'))->toBe('共同 1');

    $oldest = $this->getJson('/api/v1/search?q=共同&sort=oldest')->json('data.0.title');
    expect($oldest)->toBe('共同 25')->and(SearchService::keywords('a  b a c d e f'))->toBe(['a', 'b', 'c', 'd', 'e']);
});

// ---- M19 bookmarks ----

it('requires login for bookmarks', function () {
    $this->getJson('/api/v1/me/bookmarks')->assertStatus(401);
    $this->postJson('/api/v1/me/bookmarks', ['content_type' => 'article', 'content_id' => 1])->assertStatus(401);
});

// D20–D23 / D25 — idempotent, own only, placeholders when content becomes unavailable.
it('bookmarks idempotently and keeps a safe placeholder when content is gone', function () {
    $member = User::factory()->create();
    $article = liveArticle(['title' => '收藏文章']);
    $hidden = liveArticle(['status' => Article::STATUS_DRAFT]);

    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'article', 'content_id' => $article->id])->assertCreated();
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'article', 'content_id' => $article->id])->assertOk();
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'article', 'content_id' => $hidden->id])->assertNotFound();
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'notice', 'content_id' => 1])->assertStatus(422)->assertJsonPath('error.code', 'BOOKMARK_TYPE_DISABLED');
    expect(Bookmark::query()->count())->toBe(1);

    $article->update(['status' => Article::STATUS_DRAFT]);
    $list = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/bookmarks')->assertOk();
    expect($list->json('data.0.available'))->toBeFalse()
        ->and($list->json('data.0.item'))->toBeNull()
        ->and($list->getContent())->not->toContain('收藏文章')
        ->and($list->json('meta.count'))->toBe(1);

    // Another member sees nothing; remove and clear.
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/me/bookmarks')->assertJsonCount(0, 'data');
    $this->actingAs($member, 'sanctum')->deleteJson("/api/v1/me/bookmarks/article/{$article->id}")->assertOk()->assertJsonPath('data.bookmarked', false);
    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'event', 'content_id' => s9Event()->id])->assertCreated();
    $this->actingAs($member, 'sanctum')->deleteJson('/api/v1/me/bookmarks')->assertOk()->assertJsonPath('data.deleted', 1);
});

// D13 / G10 — 1,000 bookmarks including placeholders; account deletion cascades.
it('stops at 1,000 bookmarks including placeholders and cascades with the account', function () {
    $member = User::factory()->create();
    $rows = collect(range(1, Bookmark::MAX_PER_MEMBER))->map(fn (int $i) => ['user_id' => $member->id, 'content_type' => 'article', 'content_id' => 100000 + $i, 'created_at' => now()])->all();
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table('bookmarks')->insert($chunk);
    }

    $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/bookmarks', ['content_type' => 'article', 'content_id' => liveArticle()->id])
        ->assertStatus(409)->assertJsonPath('error.code', 'BOOKMARK_LIMIT_REACHED');

    $member->delete();
    expect(Bookmark::query()->count())->toBe(0);
});
