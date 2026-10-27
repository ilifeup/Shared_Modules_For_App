<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleCommentAttachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['storage_path'];

    protected function casts(): array
    {
        return [
            'view_expires_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'article_comment_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isViewable(): bool
    {
        return $this->removed_at === null
            && $this->view_expires_at !== null
            && $this->view_expires_at->isFuture()
            // Only files written by the private comment-attachment uploader may be served.
            // A malformed persisted path must never expose another local-disk file.
            && is_string($this->storage_path)
            && str_starts_with($this->storage_path, 'private/article-comment-attachments/')
            && strlen($this->storage_path) > strlen('private/article-comment-attachments/')
            && ! str_contains($this->storage_path, '..')
            && ! str_contains($this->storage_path, '\\');
    }
}
