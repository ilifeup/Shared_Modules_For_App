<?php

use App\Models\User;

it('denies attachment upload by default regardless of membership tier', function () {
    $member = User::factory()->create();
    expect($member->canUploadArticleAttachments())->toBeFalse();
});

it('allows an active verified member when admin explicitly grants upload access', function () {
    $member = User::factory()->create([
        'attachment_upload_enabled' => false,
    ]);
    // Protected entitlement cannot be mass-assigned from ordinary member input.
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeTrue();
});

it('rejects expired, disabled and inactive attachment entitlements', function () {
    $member = User::factory()->create();
    $member->forceFill(['attachment_upload_enabled' => true, 'attachment_upload_expires_at' => now()->subSecond()])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();

    $member->forceFill(['attachment_upload_expires_at' => now()->addDay()])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeTrue();

    $member->forceFill(['attachment_upload_enabled' => false])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();

    $member->forceFill(['attachment_upload_enabled' => true, 'is_active' => false])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();
});

it('never serializes private admin notes or attachment settings', function () {
    $member = User::factory()->create();
    $member->forceFill([
        'admin_identity_note' => 'private-admin-only-marker',
        'attachment_upload_enabled' => true,
        'attachment_weekly_upload_count' => 8,
        'attachment_weekly_upload_bytes' => 8388608,
        'attachment_upload_expires_at' => now()->addDay(),
    ])->save();

    $json = json_encode($member->fresh()->toArray());
    expect($json)->not->toContain('private-admin-only-marker')
        ->not->toContain('admin_identity_note')
        ->not->toContain('attachment_upload_enabled')
        ->not->toContain('attachment_upload_expires_at')
        ->not->toContain('attachment_weekly_upload_count')
        ->not->toContain('attachment_weekly_upload_bytes');
});

it('requires stored verified membership for manual attachment entitlement', function () {
    $member = User::factory()->unverified()->create();
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();

    $member->forceFill(['email_verified_at' => now()])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();

    $member->forceFill(['verified_status' => 2])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeTrue();
});

it('does not grant attachment upload through a contact verification timestamp or membership tier alone', function () {
    $member = User::factory()->unverified()->create([
        'email_verified_at' => now(),
    ]);
    $member->forceFill(['attachment_upload_enabled' => true])->save();

    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();
    $member->forceFill(['verified_status' => 2])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeTrue();
    $member->forceFill(['verified_status' => 1])->save();
    expect($member->fresh()->canUploadArticleAttachments())->toBeFalse();
});

it('does not expose attachment access to a missing attachment', function () {
    $member = User::factory()->create(['verified_status' => 2]);
    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/attachments/999999999/access')
        ->assertNotFound();
});

it('does not stream missing private attachment content', function () {
    $member = User::factory()->create(['verified_status' => 2]);
    $this->actingAs($member, 'sanctum')
        ->get('/api/v1/attachments/999999999/content')
        ->assertNotFound();
});

it('rejects upload attempts against nonexistent comments', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2]);
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    $file = \Illuminate\Http\UploadedFile::fake()->create('document.pdf', 20, 'application/pdf');
    $this->actingAs($member, 'sanctum')
        ->post('/api/v1/article-comments/999999999/attachments', ['file' => $file])
        ->assertNotFound();
});

it('uploads one private attachment per Taiwan week and rejects a second file', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    $article = \App\Models\Article::query()->create([
        'title' => 'Private upload test',
        'body_html' => '<p>test</p>',
        'status' => \App\Models\Article::STATUS_PUBLISHED,
        'visibility' => \App\Models\Article::VISIBILITY_PUBLIC,
    ]);
    $comment = \App\Models\ArticleComment::query()->create([
        'article_id' => $article->id,
        'user_id' => $member->id,
        'body' => 'Attachment test',
        'status' => \App\Models\ArticleComment::STATUS_VISIBLE,
    ]);
    $this->actingAs($member, 'sanctum');
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('first.pdf', 20, 'application/pdf'),
    ])->assertCreated()->assertJsonStructure(['data' => ['id', 'view_expires_at']])
        ->assertDontSee('storage_path');
    $this->assertDatabaseCount('article_comment_attachments', 1);
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => \Illuminate\Http\UploadedFile::fake()->create('second.pdf', 20, 'application/pdf'),
    ])->assertStatus(429);
    $this->assertDatabaseCount('article_comment_attachments', 1);
});
