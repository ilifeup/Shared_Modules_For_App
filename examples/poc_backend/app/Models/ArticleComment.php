<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * S8 M05-B comment. Public immediately after the blacklist check; hidden by the AI
 * review or an admin; "deleted" keeps the row (audit) but never shows it again.
 */
class ArticleComment extends Model
{
    public const STATUS_VISIBLE = 'visible';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = ['article_id', 'user_id', 'body', 'status', 'hidden_reason'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    /** @return HasMany<ArticleCommentAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ArticleCommentAttachment::class, 'article_comment_id');
    }

    /**
     * @return HasMany<CommentModerationCase, $this>
     */
    public function moderationCases(): HasMany
    {
        return $this->hasMany(CommentModerationCase::class);
    }
}
