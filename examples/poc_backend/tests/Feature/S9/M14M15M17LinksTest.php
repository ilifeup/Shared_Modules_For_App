<?php

use App\Filament\Pages\LinkAnalyticsReport;
use App\Models\Article;
use App\Models\CalendarEvent;
use App\Models\LinkClick;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlacement;
use App\Models\User;
use App\Services\Analytics\LinkAnalytics;
use App\Services\Analytics\LinkReport;
use App\Services\Marketing\MarketingService;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

const BROWSER_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

function stats(): object
{
    return DB::table('link_daily_stats')->selectRaw('SUM(total_clicks) t, SUM(dedup_clicks) d, SUM(opens) o, SUM(bot_excluded) b, SUM(abnormal_excluded) a')->first();
}

// ---- M14 links and web fallback ----

// D07 / D09 / D10 — fixed-id HTTPS URLs; OG only for public content; member content hides its title.
it('serves web fallback pages with OG for public content only', function () {
    config(['deeplinks.base_url' => 'https://tiandi.example']);
    $public = liveArticle(['title' => '公開標題', 'summary' => '公開摘要']);
    $members = liveArticle(['title' => '會員秘密', 'visibility' => Article::VISIBILITY_MEMBERS]);

    $this->get("/articles/{$public->id}", ['User-Agent' => BROWSER_UA])->assertOk()
        ->assertSee('<meta property="og:title" content="公開標題">', false)
        ->assertSee('https://tiandi.example/articles/'.$public->id, false);
    $this->get("/articles/{$members->id}")->assertOk()->assertSee('僅限會員閱讀')->assertDontSee('會員秘密')->assertDontSee('公開摘要');
    $this->get('/articles/'.$public->slug)->assertOk()->assertSee('公開標題'); // S8 slug links still work
    $this->get('/articles/999999')->assertNotFound();

    $event = s9Event(['title' => '公開活動', 'audience' => CalendarEvent::AUDIENCE_PUBLIC]);
    $this->get("/events/{$event->id}")->assertOk()->assertSee('og:title', false)->assertSee('公開活動');
    $hidden = s9Event(['title' => '會員活動標題']);
    $this->get("/events/{$hidden->id}")->assertOk()->assertDontSee('會員活動標題');
});

// D23 — share endpoint follows view permission and the App's share types.
it('returns share URLs only for content the viewer can open', function () {
    config(['deeplinks.base_url' => 'https://tiandi.example']);
    $members = liveArticle(['visibility' => Article::VISIBILITY_MEMBERS]);

    $this->getJson("/api/v1/content/article/{$members->id}/share")->assertNotFound();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/content/article/{$members->id}/share")
        ->assertOk()->assertJsonPath('data.url', 'https://tiandi.example/articles/'.$members->id)->assertJsonPath('data.is_public', false);

    app(Settings::class)->put(['content_share_types' => 'event']);
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/content/article/{$members->id}/share")
        ->assertJsonPath('data.shareable', false)->assertJsonPath('data.url', null);
});

// D17 — association files only once the real IDs are configured.
it('serves App Links and Universal Links files only when configured', function () {
    $this->get('/.well-known/assetlinks.json')->assertNotFound();
    $this->get('/.well-known/apple-app-site-association')->assertNotFound();

    config(['deeplinks.android.package' => 'com.example.app', 'deeplinks.android.sha256_fingerprints' => ['AA:BB'], 'deeplinks.ios.app_id' => 'TEAM.com.example.app']);
    $this->get('/.well-known/assetlinks.json')->assertOk()->assertJsonPath('0.target.package_name', 'com.example.app')->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'AA:BB');
    $this->get('/.well-known/apple-app-site-association')->assertOk()->assertJsonPath('applinks.details.0.appID', 'TEAM.com.example.app')
        ->assertJsonPath('applinks.details.0.paths', ['/articles/*', '/events/*', '/notices/*', '/checkin/l/*']);
});

// ---- M17 analytics ----

// D05 / D09 / D10 / D13 / D25 — no IP stored, daily hash, bots excluded, opens only when reported once.
it('records clicks without IP, dedupes per day, excludes bots and counts an open once', function () {
    $article = liveArticle();
    $page = $this->get("/articles/{$article->id}", ['User-Agent' => BROWSER_UA, 'Referer' => 'https://line.me/R/']);
    $page->assertOk();
    $this->get("/articles/{$article->id}", ['User-Agent' => BROWSER_UA]);
    $this->get("/articles/{$article->id}", ['User-Agent' => 'facebookexternalhit/1.1']);

    $click = LinkClick::query()->orderBy('id')->firstOrFail();
    expect($click->source)->toBe('line')->and($click->device_class)->toBe('mobile')
        ->and(array_keys($click->getAttributes()))->not->toContain('ip');
    expect((array) stats())->toMatchArray(['t' => 2, 'd' => 1, 'o' => 0, 'b' => 1]);

    $this->postJson('/api/v1/links/opens', ['click_id' => $click->click_id])->assertStatus(202)->assertJsonPath('data.counted', true);
    $this->postJson('/api/v1/links/opens', ['click_id' => $click->click_id])->assertJsonPath('data.counted', false);
    expect((int) stats()->o)->toBe(1);

    // App open counts the click and the open together.
    $this->postJson('/api/v1/links/opens', ['content_type' => 'article', 'content_id' => $article->id], ['User-Agent' => 'Dart/3.9 (dart:io)'])->assertJsonPath('data.counted', true);
    expect((array) stats())->toMatchArray(['t' => 3, 'o' => 2]);

    // The hash changes the next statistics day.
    $first = $click->dedupe_hash;
    $this->travel(1)->days();
    $this->get("/articles/{$article->id}", ['User-Agent' => BROWSER_UA]);
    expect(LinkClick::query()->latest('id')->value('dedupe_hash'))->not->toBe($first);
});

// D28 — abnormal bursts are excluded and counted separately.
it('excludes abnormal bursts from the human numbers', function () {
    $article = liveArticle();
    foreach (range(1, LinkAnalytics::ABNORMAL_LIMIT + 3) as $ignored) {
        $this->postJson('/api/v1/links/clicks', ['content_type' => 'article', 'content_id' => $article->id], ['User-Agent' => BROWSER_UA])->assertStatus(202);
    }

    expect((array) stats())->toMatchArray(['t' => LinkAnalytics::ABNORMAL_LIMIT, 'd' => 1, 'a' => 3]);
});

// D11 / D22 — 90 days raw, 24 months aggregates.
it('purges raw clicks after 90 days and aggregates after 24 months', function () {
    $article = liveArticle();
    $this->get("/articles/{$article->id}", ['User-Agent' => BROWSER_UA]);

    expect(app(LinkAnalytics::class)->purge(now()->addDays(91)))->toMatchArray(['raw_deleted' => 1, 'aggregates_deleted' => 0])
        ->and(app(LinkAnalytics::class)->purge(now()->addMonths(25)))->toMatchArray(['aggregates_deleted' => 1]);
});

// D06 / D23 / D19 — separate analytics permission; CSV of aggregates only.
it('shows the report only with analytics.view and exports aggregates as CSV', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $article = liveArticle(['title' => '報表文章']);
    $this->get("/articles/{$article->id}", ['User-Agent' => BROWSER_UA]);

    $this->actingAs(makeAdmin(), 'web')->get('/admin/link-analytics')->assertForbidden();
    $analyst = makeAdmin();
    $analyst->assignRole(Role::findOrCreate('analyst', 'web'));
    $this->actingAs($analyst, 'web')->get('/admin/link-analytics')->assertOk();
    expect(LinkAnalyticsReport::canAccess())->toBeTrue();

    $report = app(LinkReport::class);
    [$from, $to] = $report->range('7d');
    expect($report->build($from, $to, [])['summary']['total'])->toBe(1)
        ->and($report->build($from, $to, [])['top'][0]['label'])->toContain('報表文章')
        ->and($report->csv($from, $to, []))->toContain('article,'.$article->id.',direct,mobile,1,1,0')->not->toContain(BROWSER_UA);
});

// ---- M15 marketing ----

function campaign(array $attributes = [], ?string $placement = 'home_banner'): MarketingCampaign
{
    $campaign = MarketingCampaign::query()->create(array_merge([
        'title' => '活動推廣', 'button_text' => '查看', 'target_type' => 'url', 'target_url' => 'https://example.com/a',
        'status' => MarketingCampaign::DRAFT, 'priority' => 0, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
    ], $attributes));
    if ($placement !== null) {
        $campaign->placements()->sync([MarketingPlacement::query()->where('code', $placement)->value('id')]);
    }

    return $campaign;
}

// D25 / D26 — the server validates before publishing.
it('validates campaigns on the server before publishing', function () {
    $service = app(MarketingService::class);
    $admin = makeAdmin();

    foreach ([
        ['target_url' => 'http://example.com'],
        ['target_url' => 'https://user:pw@example.com'],
        ['ends_at' => now()->subDays(2)],
        ['target_type' => 'article', 'target_id' => liveArticle(['status' => Article::STATUS_DRAFT])->id, 'target_url' => null],
    ] as $bad) {
        expect(fn () => $service->publish(campaign($bad), $admin))->toThrow(ApiException::class);
    }
    expect(fn () => $service->publish(campaign([], null), $admin))->toThrow(ApiException::class, '展示區塊');

    $ok = campaign();
    $service->publish($ok, $admin);
    expect($ok->refresh()->state())->toBe('active');
});

// D05 / D10 — five states from the server clock.
it('derives the five campaign states from the server clock', function () {
    expect(campaign()->state())->toBe('draft')
        ->and(campaign(['status' => 'published', 'starts_at' => now()->addHour()])->state())->toBe('scheduled')
        ->and(campaign(['status' => 'published'])->state())->toBe('active')
        ->and(campaign(['status' => 'published', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()])->state())->toBe('ended')
        ->and(campaign(['status' => 'disabled'])->state())->toBe('disabled');
});

// D09 / D13 / D17 / D22 / D27 — priority order, placement limit, viewer permission, M14 URL with utm.
it('lists active campaigns by priority within the placement limit and the viewer permission', function () {
    config(['deeplinks.base_url' => 'https://tiandi.example']);
    foreach ([1, 5, 3, 2] as $priority) {
        campaign(['title' => 'P'.$priority, 'status' => 'published', 'priority' => $priority]);
    }
    $members = liveArticle(['visibility' => Article::VISIBILITY_MEMBERS]);
    campaign(['title' => '會員', 'status' => 'published', 'priority' => 9, 'target_type' => 'article', 'target_id' => $members->id, 'target_url' => null]);
    campaign(['title' => '未開始', 'status' => 'published', 'priority' => 10, 'starts_at' => now()->addHour()]);

    $visitor = $this->getJson('/api/v1/marketing/placements/home_banner')->assertOk();
    expect(collect($visitor->json('data'))->pluck('title')->all())->toBe(['P5', 'P3', 'P2']);

    $member = $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/marketing/placements/home_banner')->assertOk();
    expect($member->json('data.0.title'))->toBe('會員')
        ->and($member->json('data.0.target.url'))->toBe("https://tiandi.example/articles/{$members->id}?utm_source=app&utm_medium=marketing&utm_campaign=c".$member->json('data.0.id'))
        ->and($member->json('data.0.target.app_path'))->toBe("/articles/{$members->id}");

    $this->getJson('/api/v1/marketing/placements/unknown_code')->assertOk()->assertJsonCount(0, 'data');
});

// D19 / D21 / D29 — duplicate as a new draft; missing images hide the campaign with an error.
it('duplicates as a draft and hides campaigns whose image is missing', function () {
    Storage::fake('local');
    $admin = makeAdmin();
    $path = UploadedFile::fake()->image('b.png')->store('marketing', 'local');
    $original = campaign(['status' => 'published', 'image_path' => $path]);

    $copy = app(MarketingService::class)->duplicate($original, $admin);
    expect($copy->status)->toBe('draft')->and($copy->starts_at)->toBeNull()->and($copy->placements()->count())->toBe(1);

    $this->get("/api/v1/marketing/campaigns/{$original->id}/image")->assertOk();
    $this->get("/api/v1/marketing/campaigns/{$copy->id}/image")->assertNotFound();

    Storage::disk('local')->delete($path);
    $this->getJson('/api/v1/marketing/placements/home_banner')->assertJsonCount(0, 'data');
    expect($original->refresh()->last_error)->toBe('圖片檔案不存在');

    app(MarketingService::class)->delete($original, $admin);
    expect(MarketingCampaign::query()->find($original->id))->toBeNull();
});
