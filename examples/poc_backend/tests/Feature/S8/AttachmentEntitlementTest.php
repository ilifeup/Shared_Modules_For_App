<?php

use App\Models\Article;
use App\Models\ArticleComment;
use App\Models\ArticleCommentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2]);
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    $file = UploadedFile::fake()->create('document.pdf', 20, 'application/pdf');
    $this->actingAs($member, 'sanctum')
        ->post('/api/v1/article-comments/999999999/attachments', ['file' => $file])
        ->assertNotFound();
});

it('uploads one private attachment per Taiwan week and rejects a second file', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $member->forceFill(['attachment_upload_enabled' => true])->save();
    $article = Article::query()->create([
        'title' => 'Private upload test',
        'body_html' => '<p>test</p>', 'author_name' => 'Test Author',
        'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id,
        'user_id' => $member->id,
        'body' => 'Attachment test',
        'status' => ArticleComment::STATUS_VISIBLE,
    ]);
    $this->actingAs($member, 'sanctum');
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('first.pdf', 20, 'application/pdf'),
    ])->assertCreated()->assertJsonStructure(['data' => ['id', 'view_expires_at']])
        ->assertDontSee('storage_path');
    $this->assertDatabaseCount('article_comment_attachments', 1);
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('second.pdf', 20, 'application/pdf'),
    ])->assertStatus(429);
    $this->assertDatabaseCount('article_comment_attachments', 1);
});

it('blocks an expired or removed attachment before private file streaming', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $article = Article::query()->create([
        'title' => 'Expiry test', 'body_html' => '<p>test</p>', 'author_name' => 'Test Author',
        'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $member->id,
        'body' => 'Expiry comment', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);
    $path = 'private/article-comment-attachments/expiry.pdf';
    Storage::disk('local')->put($path, 'private-pdf');
    $attachment = ArticleCommentAttachment::query()->create([
        'article_comment_id' => $comment->id, 'user_id' => $member->id,
        'storage_path' => $path, 'original_name' => 'expiry.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 11,
        'view_expires_at' => now()->subSecond(),
    ]);
    $this->actingAs($member, 'sanctum');
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertNotFound();

    $attachment->update(['view_expires_at' => now()->addDay(), 'removed_at' => now()]);
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertNotFound();
});

it('denies private attachment access when its comment is hidden or article withdrawn', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $article = Article::query()->create([
        'title' => 'Revocation test', 'body_html' => '<p>test</p>', 'author_name' => 'Test Author',
        'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $member->id,
        'body' => 'Revocation comment', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);
    $path = 'private/article-comment-attachments/revocation.pdf';
    Storage::disk('local')->put($path, 'private-pdf');
    $attachment = ArticleCommentAttachment::query()->create([
        'article_comment_id' => $comment->id, 'user_id' => $member->id,
        'storage_path' => $path, 'original_name' => 'revocation.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 11,
        'view_expires_at' => now()->addDay(),
    ]);
    $this->actingAs($member, 'sanctum');
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertOk();
    $comment->update(['status' => ArticleComment::STATUS_HIDDEN]);
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();
    $comment->update(['status' => ArticleComment::STATUS_VISIBLE]);
    $article->update(['status' => Article::STATUS_WITHDRAWN]);
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertNotFound();
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();
});

it('rejects persisted attachment paths pointing outside the private attachment directory', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $article = Article::query()->create([
        'title' => 'Attachment path isolation', 'body_html' => '<p>test</p>',
        'author_name' => 'Test Author', 'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $member->id,
        'body' => 'Path isolation', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);
    Storage::disk('local')->put('private/unrelated-document.pdf', 'not-an-attachment');
    $attachment = ArticleCommentAttachment::query()->create([
        'article_comment_id' => $comment->id, 'user_id' => $member->id,
        'storage_path' => 'private/unrelated-document.pdf',
        'original_name' => 'unrelated-document.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 17,
        'view_expires_at' => now()->addDay(),
    ]);

    $this->actingAs($member, 'sanctum');
    expect($attachment->fresh()->isViewable())->toBeFalse();
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertNotFound();
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();

    $attachment->update(['storage_path' => 'private/article-comment-attachments/../unrelated-document.pdf']);
    expect($attachment->fresh()->isViewable())->toBeFalse();
    $this->getJson('/api/v1/attachments/'.$attachment->id.'/access')->assertNotFound();
    $this->get('/api/v1/attachments/'.$attachment->id.'/content')->assertNotFound();
});

it('honors the administrator weekly upload count override', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $member->forceFill([
        'attachment_upload_enabled' => true,
        'attachment_weekly_upload_count' => 2,
    ])->save();
    $article = Article::query()->create([
        'title' => 'Count quota test', 'body_html' => '<p>test</p>',
        'author_name' => 'Test Author', 'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $member->id,
        'body' => 'Count quota', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);

    $this->actingAs($member, 'sanctum');
    foreach (['first.pdf', 'second.pdf'] as $name) {
        $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
            'file' => UploadedFile::fake()->create($name, 20, 'application/pdf'),
        ])->assertCreated();
    }
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('third.pdf', 20, 'application/pdf'),
    ])->assertStatus(429);
    $this->assertDatabaseCount('article_comment_attachments', 2);
});

it('enforces the administrator weekly byte quota independently of count', function () {
    Storage::fake('local');
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $member->forceFill([
        'attachment_upload_enabled' => true,
        'attachment_weekly_upload_count' => 3,
        'attachment_weekly_upload_bytes' => 25 * 1024,
    ])->save();
    $article = Article::query()->create([
        'title' => 'Byte quota test', 'body_html' => '<p>test</p>',
        'author_name' => 'Test Author', 'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $member->id,
        'body' => 'Byte quota', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);

    $this->actingAs($member, 'sanctum');
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('first.pdf', 20, 'application/pdf'),
    ])->assertCreated();
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
    ])->assertStatus(429);
    $this->assertDatabaseCount('article_comment_attachments', 1);

    // Increasing the admin byte limit does not reset already-consumed quota.
    $member->forceFill(['attachment_weekly_upload_bytes' => 40 * 1024])->save();
    $this->post('/api/v1/article-comments/'.$comment->id.'/attachments', [
        'file' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
    ])->assertCreated();
    $this->assertDatabaseCount('article_comment_attachments', 2);
});

it('discovers only authorized private attachment IDs and expiry without leaking file metadata', function () {
    $owner = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $reader = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $article = Article::query()->create([
        'title' => 'Attachment discovery', 'body_html' => '<p>test</p>',
        'author_name' => 'Test Author', 'status' => Article::STATUS_PUBLISHED,
        'visibility' => Article::VISIBILITY_PUBLIC,
    ]);
    $comment = ArticleComment::query()->create([
        'article_id' => $article->id, 'user_id' => $owner->id,
        'body' => 'Discover private files', 'status' => ArticleComment::STATUS_VISIBLE,
    ]);
    $base = [
        'article_comment_id' => $comment->id, 'user_id' => $owner->id,
        'original_name' => 'secret-document.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 12, 'view_expires_at' => now()->addDay(),
    ];
    $visible = ArticleCommentAttachment::query()->create($base + [
        'storage_path' => 'private/article-comment-attachments/valid.pdf',
    ]);
    ArticleCommentAttachment::query()->create(array_merge($base, [
        'storage_path' => 'private/article-comment-attachments/expired.pdf',
        'view_expires_at' => now()->subDay(),
    ]));
    ArticleCommentAttachment::query()->create(array_merge($base, [
        'storage_path' => 'private/article-comment-attachments/removed.pdf',
        'removed_at' => now(),
    ]));
    ArticleCommentAttachment::query()->create(array_merge($base, [
        'storage_path' => 'private/unrelated.pdf',
    ]));

    $this->actingAs($reader, 'sanctum');
    $this->getJson('/api/v1/article-comments/'.$comment->id.'/attachments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonStructure(['data' => [['id', 'view_expires_at']]])
        ->assertDontSee('secret-document.pdf')
        ->assertDontSee('storage_path')
        ->assertDontSee('mime_type')
        ->assertDontSee('size_bytes');
    $this->getJson('/api/v1/attachments/'.$visible->id.'/access')->assertOk();

    $article->update(['visibility' => Article::VISIBILITY_RESTRICTED]);
    $this->getJson('/api/v1/article-comments/'.$comment->id.'/attachments')->assertForbidden();
    $article->update(['visibility' => Article::VISIBILITY_PUBLIC]);
    $comment->update(['status' => ArticleComment::STATUS_HIDDEN]);
    $this->getJson('/api/v1/article-comments/'.$comment->id.'/attachments')->assertNotFound();
    $comment->update(['status' => ArticleComment::STATUS_VISIBLE]);
    $article->update(['status' => Article::STATUS_WITHDRAWN]);
    $this->getJson('/api/v1/article-comments/'.$comment->id.'/attachments')->assertNotFound();
});

it('requires an authenticated database-verified member to discover comment attachments', function () {
    $this->getJson('/api/v1/article-comments/123/attachments')->assertUnauthorized();
    $member = User::factory()->unverified()->create(['verified_status' => 1]);
    $this->actingAs($member, 'sanctum')
        ->getJson('/api/v1/article-comments/123/attachments')->assertForbidden();
});
