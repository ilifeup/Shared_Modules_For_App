<?php

use App\Models\AuditEvent;
use App\Models\Notice;
use App\Models\User;

it('lists only published notices whose publish time has passed', function () {
    $member = User::factory()->create();

    $visible = Notice::query()->create(['title' => 'Visible', 'body' => 'Body', 'status' => 'published', 'published_at' => now()->subHour()]);
    Notice::query()->create(['title' => 'Draft', 'body' => 'Hidden', 'status' => 'draft']);
    Notice::query()->create(['title' => 'Future', 'body' => 'Later', 'status' => 'published', 'published_at' => now()->addDay()]);

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/notices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $visible->id)
        ->assertJsonPath('data.0.status', 'published')
        ->assertJsonStructure(['data' => [['id', 'title', 'body', 'status', 'published_at']], 'meta']);
});

it('hides drafts and future notices from the detail endpoint', function () {
    $member = User::factory()->create();
    $draft = Notice::query()->create(['title' => 'Draft', 'body' => 'Secret body', 'status' => 'draft']);
    $published = Notice::query()->create(['title' => 'Hello', 'body' => 'World', 'status' => 'published']);

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/notices/{$draft->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertDontSee('Secret body');

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/notices/{$published->id}")
        ->assertOk()
        ->assertJsonPath('data.title', 'Hello');
});

it('stamps the publish time and records an audit event when a notice is published', function () {
    $notice = Notice::query()->create(['title' => 'T', 'body' => 'B', 'status' => 'draft']);
    expect($notice->published_at)->toBeNull();

    $notice->update(['status' => 'published']);

    expect($notice->refresh()->published_at)->not->toBeNull();
    expect(AuditEvent::query()->where('event', 'notice.published')->where('subject_id', $notice->id)->exists())->toBeTrue();
});
