<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** @var list<string> */
    protected $fillable = ['title', 'body', 'status', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
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
            if ($notice->status === self::STATUS_PUBLISHED && ($notice->wasRecentlyCreated || $notice->wasChanged('status'))) {
                AuditEvent::record('notice.published', auth()->id(), $notice);
            }
        });
    }

    /**
     * Notices a member may read: published and with a publish time that has been reached.
     *
     * @param  Builder<Notice>  $query
     */
    public function scopeVisibleToMembers(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
