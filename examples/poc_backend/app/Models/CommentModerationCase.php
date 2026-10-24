<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One round of reports for a comment. `active` is 1 while open (unique per comment),
 * NULL once closed; later reports open a new round.
 *
 * @property Carbon|null $ai_triggered_at
 */
class CommentModerationCase extends Model
{
    protected $fillable = ['article_comment_id', 'round', 'active', 'report_count', 'ai_status'];

    protected function casts(): array
    {
        return [
            'ai_triggered_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'report_count' => 'integer',
            'active' => 'integer',
            'round' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->active === 1;
    }

    /**
     * @return BelongsTo<ArticleComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(ArticleComment::class, 'article_comment_id');
    }

    /**
     * @return HasMany<CommentReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }
}
