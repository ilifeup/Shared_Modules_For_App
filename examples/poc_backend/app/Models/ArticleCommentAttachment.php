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
            && $this->view_expires_at->isFuture();
    }
}
