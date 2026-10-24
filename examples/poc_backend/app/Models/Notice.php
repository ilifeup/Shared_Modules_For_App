<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** S3: taken down after publication; never visible again unless re-published. */
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_WITHDRAWN];

    /** Anyone, including visitors who are not logged in. */
    public const AUDIENCE_PUBLIC = 'public';

    /** Logged-in members who satisfy the verification policy (default, as in S2). */
    public const AUDIENCE_MEMBERS = 'members';

    /** @var list<string> */
    protected $fillable = ['title', 'body', 'status', 'published_at', 'publish_ends_at', 'notice_category_id', 'is_pinned', 'audience'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'publish_ends_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Publishing without a time means "publish now".
        static::saving(function (Notice $notice): void {
            if ($notice->status === self::STATUS_PUBLISHED && $notice->published_at === null) {
                $notice->published_at = now();
            }
        });

        static::saved(function (Notice $notice): void {
            if ($notice->wasRecentlyCreated || $notice->wasChanged('status')) {
                match ($notice->status) {
                    self::STATUS_PUBLISHED => AuditEvent::record('notice.published', auth()->id(), $notice),
                    self::STATUS_WITHDRAWN => AuditEvent::record('notice.withdrawn', auth()->id(), $notice),
                    default => null,
                };
            }
        });
    }

    /**
     * Published, start time reached, end time (if any) not reached.
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $now = now();

        $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('publish_ends_at')->orWhere('publish_ends_at', '>', $now));
    }

    /**
     * Notices a member may read (public and member notices).
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeVisibleToMembers(Builder $query): void
    {
        $query->visible();
    }

    /**
     * Notices visitors may read without logging in.
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeVisibleToPublic(Builder $query): void
    {
        $query->visible()->where('audience', self::AUDIENCE_PUBLIC);
    }

    /**
     * Pinned first, then newest.
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<NoticeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    /**
     * @return HasMany<NoticeRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(NoticeRead::class);
    }
}
