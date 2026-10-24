<?php

use App\Filament\Resources\Notices\Pages\CreateNotice;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\AuditEvent;
use App\Models\Notice;
use App\Models\NoticeCategory;
use App\Models\NoticeRead;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

function notice(array $attributes = []): Notice
{
    return Notice::query()->create(array_merge([
        'title' => 'T',
        'body' => 'B',
        'status' => Notice::STATUS_PUBLISHED,
        'published_at' => now()->subHour(),
    ], $attributes));
}

it('keeps S2 notices working with default values', function () {
    $old = notice(['title' => 'S2 公告']);

    expect($old->refresh()->audience)->toBe(Notice::AUDIENCE_MEMBERS)
        ->and($old->is_pinned)->toBeFalse()
        ->and($old->notice_category_id)->toBeNull();

    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/notices')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'S2 公告')
        ->assertJsonPath('data.0.category', null)
        ->assertJsonPath('data.0.is_read', false)
        ->assertJsonStructure(['data' => [['id', 'title', 'body', 'status', 'published_at']]]);
});

it('honours the publish window and the withdrawn state', function () {
    $member = User::factory()->create();
    $current = notice(['title' => '進行中', 'publish_ends_at' => now()->addDay()]);
    $ended = notice(['title' => '已下架', 'publish_ends_at' => now()->subMinute()]);
    $future = notice(['title' => '未上架', 'published_at' => now()->addHour()]);
    $withdrawn = notice(['title' => '已撤回', 'status' => Notice::STATUS_WITHDRAWN]);

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $current->id);

    foreach ([$ended, $future, $withdrawn] as $hidden) {
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/notices/{$hidden->id}")->assertNotFound();
    }

    $this->travel(2)->days();
    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $future->id);
});

it('lists pinned notices first and filters by category', function () {
    $news = NoticeCategory::query()->create(['name' => '最新消息']);
    $events = NoticeCategory::query()->create(['name' => '活動']);
    $older = notice(['title' => '置頂舊公告', 'is_pinned' => true, 'published_at' => now()->subDays(5), 'notice_category_id' => $news->id]);
    $newer = notice(['title' => '一般新公告', 'published_at' => now()->subMinute(), 'notice_category_id' => $events->id]);
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')
        ->assertJsonPath('data.0.id', (string) $older->id)
        ->assertJsonPath('data.0.is_pinned', true)
        ->assertJsonPath('data.0.category.name', '最新消息')
        ->assertJsonPath('data.1.id', (string) $newer->id);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/notices?category={$events->id}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $newer->id);

    $this->getJson('/api/v1/notice-categories')->assertOk()->assertJsonCount(2, 'data');
});

it('serves public notices without login and keeps member notices private', function () {
    $public = notice(['title' => '公開公告', 'audience' => Notice::AUDIENCE_PUBLIC]);
    $members = notice(['title' => '會員公告']);

    $this->getJson('/api/v1/public/notices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $public->id);
    $this->getJson("/api/v1/public/notices/{$members->id}")->assertNotFound();
    $this->getJson('/api/v1/notices')->assertUnauthorized();

    // Members see both.
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/notices')->assertJsonCount(2, 'data');
});

it('records read status once per member, even when submitted repeatedly', function () {
    $a = notice(['title' => 'A']);
    $b = notice(['title' => 'B']);
    $member = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')->assertJsonPath('meta.unread_count', 2);

    foreach (range(1, 3) as $_) {
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/notices/{$a->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
    }

    expect(NoticeRead::query()->where('notice_id', $a->id)->where('user_id', $member->id)->count())->toBe(1);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/notices/{$a->id}")->assertJsonPath('data.is_read', true);
    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')->assertJsonPath('meta.unread_count', 1);

    // Read status is per member.
    $this->app['auth']->forgetGuards();
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/notices')->assertJsonPath('meta.unread_count', 2);

    // Hidden notices cannot be marked.
    $draft = notice(['status' => Notice::STATUS_DRAFT]);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/notices/{$draft->id}/read")->assertNotFound();
    expect($b->reads()->count())->toBe(0);
});

it('lets the admin create, pin, schedule and withdraw notices in Filament', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin());
    $category = NoticeCategory::query()->create(['name' => '最新消息']);

    Livewire::test(CreateNotice::class)
        ->fillForm([
            'title' => '置頂公告',
            'body' => '內容',
            'notice_category_id' => $category->id,
            'audience' => Notice::AUDIENCE_PUBLIC,
            'status' => Notice::STATUS_PUBLISHED,
            'is_pinned' => true,
            'publish_ends_at' => now()->addWeek()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Notice::query()->where('title', '置頂公告')->firstOrFail();
    expect($created->is_pinned)->toBeTrue()
        ->and($created->audience)->toBe('public')
        ->and($created->publish_ends_at)->not->toBeNull();

    Livewire::test(ListNotices::class)
        ->assertTableActionHidden('delete', $created)
        ->callTableAction('withdraw', $created);

    expect($created->refresh()->status)->toBe(Notice::STATUS_WITHDRAWN)
        ->and(AuditEvent::query()->where('event', 'notice.withdrawn')->exists())->toBeTrue();
    $this->getJson('/api/v1/public/notices')->assertJsonCount(0, 'data');
});
