<?php

use App\Models\Article;
use App\Models\ArticleLike;
use App\Models\AuditEvent;
use App\Models\User;

// G08
it('lets members like once, unlike, and keeps unlisted likes counted', function () {
    $article = liveArticle();
    $listed = articleMember(['nickname' => '公開者', 'show_in_like_list' => true]);
    $hidden = articleMember(['nickname' => '低調者']);

    $this->postJson("/api/v1/articles/{$article->id}/like")->assertStatus(401);

    $this->actingAs($listed, 'sanctum')->postJson("/api/v1/articles/{$article->id}/like")
        ->assertOk()->assertJsonPath('data.liked', true)->assertJsonPath('data.like_count', 1);
    // Idempotent: still one like.
    $this->actingAs($listed, 'sanctum')->postJson("/api/v1/articles/{$article->id}/like")->assertJsonPath('data.like_count', 1);
    $this->actingAs($hidden, 'sanctum')->postJson("/api/v1/articles/{$article->id}/like")->assertJsonPath('data.like_count', 2);

    expect(ArticleLike::query()->where('article_id', $article->id)->count())->toBe(2);

    $this->getJson("/api/v1/articles/{$article->id}/likes")
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.listed', [['nickname' => '公開者']])
        ->assertJsonPath('data.unlisted_count', 1)
        ->assertDontSee('低調者')
        ->assertDontSee($listed->name);

    $this->actingAs($hidden, 'sanctum')->getJson("/api/v1/articles/{$article->id}")->assertJsonPath('data.liked_by_me', true);

    $this->actingAs($hidden, 'sanctum')->deleteJson("/api/v1/articles/{$article->id}/like")
        ->assertOk()->assertJsonPath('data.liked', false)->assertJsonPath('data.like_count', 1);
    $this->actingAs($hidden, 'sanctum')->deleteJson("/api/v1/articles/{$article->id}/like")->assertJsonPath('data.like_count', 1);
});

it('refuses likes from unverified accounts', function () {
    $article = liveArticle();
    $this->actingAs(User::factory()->unverified()->create(), 'sanctum')
        ->postJson("/api/v1/articles/{$article->id}/like")->assertStatus(403);
    expect($article->refresh()->like_count)->toBe(0);
});

// G09
it('updates the unified public nickname and like-list privacy in the profile', function () {
    $member = User::factory()->create(['name' => '王小明']);
    $other = articleMember(['nickname' => '已使用']);

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/profile')
        ->assertJsonPath('data.nickname', null)
        ->assertJsonPath('data.privacy.show_in_like_list', false);

    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/me/profile', ['nickname' => '已使用'])
        ->assertStatus(422)->assertJsonValidationErrors('nickname', 'error.fields');
    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/me/profile', ['nickname' => '<b>x</b>'])->assertStatus(422);
    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/me/profile', ['nickname' => 'A'])->assertStatus(422);

    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/me/profile', ['nickname' => '小明', 'show_in_like_list' => true])
        ->assertOk()
        ->assertJsonPath('data.nickname', '小明')
        ->assertJsonPath('data.privacy.show_in_like_list', true)
        ->assertJsonPath('data.name', '王小明');

    // History shows the newest nickname.
    $article = liveArticle();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => '第一則'])->assertCreated();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/like")->assertOk();
    $this->actingAs($member, 'sanctum')->patchJson('/api/v1/me/profile', ['nickname' => '大明'])->assertOk();

    $this->getJson("/api/v1/articles/{$article->id}/comments")->assertJsonPath('data.0.nickname', '大明');
    $this->getJson("/api/v1/articles/{$article->id}/likes")->assertJsonPath('data.listed.0.nickname', '大明');
    expect($other->refresh()->nickname)->toBe('已使用');
});

// G10
it('requires a nickname, rejects blacklisted comments with a warning and publishes the rest at once', function () {
    $article = liveArticle();
    $noNick = User::factory()->create();
    $member = articleMember(['nickname' => '留言者']);

    $this->actingAs($noNick, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => '你好'])
        ->assertStatus(422)->assertJsonPath('error.code', 'NICKNAME_REQUIRED');

    foreach (['請看 https://spam.example', '請加 LINE abc123', '代辦貸款請洽'] as $bad) {
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => $bad])
            ->assertStatus(422)->assertJsonPath('error.code', 'COMMENT_BLOCKED')->assertJsonStructure(['error' => ['fields' => ['body']]]);
    }
    expect(AuditEvent::query()->where('event', 'comment.blocked')->count())->toBe(3)
        ->and($article->comments()->count())->toBe(0);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => str_repeat('長', 1001)])->assertStatus(422);

    $created = $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => '  感謝分享  '])
        ->assertCreated()
        ->assertJsonPath('data.body', '感謝分享')
        ->assertJsonPath('data.nickname', '留言者')
        ->assertJsonPath('data.is_mine', true);

    $this->getJson("/api/v1/articles/{$article->id}/comments")
        ->assertJsonPath('data.0.id', $created->json('data.id'))
        ->assertJsonPath('data.0.is_mine', false)
        ->assertDontSee($member->name)
        ->assertJsonMissingPath('data.0.user_id');
    expect(AuditEvent::query()->where('event', 'comment.created')->count())->toBe(1);
});

it('uses an admin-provided blacklist from configuration', function () {
    config(['articles.comment_blacklist' => ['/禁語/u']]);
    $article = liveArticle();
    $member = articleMember();

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => '這是禁語'])->assertStatus(422);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/articles/{$article->id}/comments", ['body' => 'https://ok.example'])->assertCreated();
});

it('hides comments of withdrawn articles', function () {
    $article = liveArticle();
    $article->update(['status' => Article::STATUS_WITHDRAWN]);
    $this->getJson("/api/v1/articles/{$article->id}/comments")->assertNotFound();
});
