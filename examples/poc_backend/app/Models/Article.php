<?php

namespace App\Models;

use App\Support\ArticleHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * S8 M05-A article. Body is sanitized HTML. Soft deletes = 垃圾桶.
 *
 * @property Carbon|null $published_at
 * @property Carbon|null $unpublish_at
 */
class Article extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_MEMBERS = 'members';

    /** Members matching ANY access rule (level or role). */
    public const VISIBILITY_RESTRICTED = 'restricted';

    protected $fillable = [
        'title', 'slug', 'summary', 'body_html', 'cover_path', 'cover_url', 'author_name',
        'status', 'published_at', 'unpublish_at', 'visibility', 'is_featured', 'featured_order',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'unpublish_at' => 'datetime',
            'is_featured' => 'boolean',
            'featured_order' => 'integer',
            'view_count' => 'integer',
            'like_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Article $article): void {
            if (blank($article->slug)) {
                $article->slug = self::uniqueSlug($article->title);
            }
        });

        static::saving(function (Article $article): void {
            $article->body_html = app(ArticleHtml::class)->sanitize((string) $article->body_html);

            if ($article->unpublish_at !== null && $article->published_at !== null && ! $article->unpublish_at->greaterThan($article->published_at)) {
                throw ValidationException::withMessages(['unpublish_at' => ['下架時間必須晚於發布時間。']]);
            }

            if ($article->status === self::STATUS_PUBLISHED && $article->published_at === null) {
                $article->published_at = now();
            }
        });

        static::saved(function (Article $article): void {
            if ($article->wasRecentlyCreated || $article->wasChanged('status')) {
                match ($article->status) {
                    self::STATUS_PUBLISHED => AuditEvent::record('article.published', auth()->id(), $article),
                    self::STATUS_WITHDRAWN => AuditEvent::record('article.withdrawn', auth()->id(), $article),
                    default => null,
                };
            }
        });
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'article';
        $base = mb_substr($base, 0, 100);
        $slug = $base.'-'.Str::lower(Str::random(6));

        while (self::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }

    /**
     * Published, start reached, not taken down (permission is checked separately).
     *
     * @param  Builder<Article>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = now();
        $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->where(fn (Builder $q) => $q->whereNull('unpublish_at')->orWhere('unpublish_at', '>', $now));
    }

    public function isLive(): bool
    {
        $now = now();

        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null && $this->published_at->lte($now)
            && ($this->unpublish_at === null || $this->unpublish_at->gt($now))
            && ! $this->trashed();
    }

    /**
     * Popularity = valid views + likes × weight (weight from Settings).
     *
     * @param  Builder<Article>  $query
     */
    public function scopeOrderByPopularity(Builder $query, int $likeWeight): void
    {
        $query->orderByRaw('(view_count + like_count * ?) desc', [$likeWeight])->orderByDesc('published_at')->orderByDesc('id');
    }

    /**
     * @return BelongsToMany<ArticleCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ArticleCategory::class);
    }

    /**
     * @return HasMany<ArticleAccessRule, $this>
     */
    public function accessRules(): HasMany
    {
        return $this->hasMany(ArticleAccessRule::class);
    }

    /**
     * @return HasMany<ArticleLike, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(ArticleLike::class);
    }

    /**
     * @return HasMany<ArticleComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ArticleComment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
