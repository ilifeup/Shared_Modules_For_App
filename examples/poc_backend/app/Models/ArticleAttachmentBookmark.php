<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAttachmentBookmark extends Model
{
    protected $fillable = ['user_id', 'attachment_id'];

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(ArticleCommentAttachment::class, 'attachment_id');
    }
}
