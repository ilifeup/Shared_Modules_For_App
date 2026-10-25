<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * S8 M05 article category; up to three levels. Cannot be deleted while it has
 * sub-categories or articles (also enforced by foreign keys).
 */
class ArticleCategory extends Model
{
    public const MAX_DEPTH = 3;

    protected $fillable = ['parent_id', 'name', 'sort'];

    protected function casts(): array
    {
        return ['depth' => 'integer', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (ArticleCategory $category): void {
            $parent = $category->parent_id ? self::query()->find($category->parent_id) : null;
            $depth = $parent ? $parent->depth + 1 : 1;

            if ($category->exists && $category->parent_id !== null && $category->isAncestorOf($category->parent_id)) {
                throw ValidationException::withMessages(['parent_id' => ['不能把分類移到自己的子分類下。']]);
            }

            if ($depth > self::MAX_DEPTH || ($category->exists && $depth + $category->subtreeHeight() - 1 > self::MAX_DEPTH)) {
                throw ValidationException::withMessages(['parent_id' => ['分類最多三層。']]);
            }

            $category->depth = $depth;
        });

        static::deleting(function (ArticleCategory $category): void {
            if ($category->children()->exists() || $category->articles()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['category' => ['此分類還有子分類或文章，無法刪除。']]);
            }
        });
    }

    /**
     * Levels in this subtree including this category (1 = no children).
     */
    public function subtreeHeight(): int
    {
        $max = 0;
        foreach ($this->children as $child) {
            $max = max($max, $child->subtreeHeight());
        }

        return $max + 1;
    }

    public function isAncestorOf(int $categoryId): bool
    {
        $current = self::query()->find($categoryId);
        while ($current !== null) {
            if ($current->id === $this->id) {
                return true;
            }
            $current = $current->parent_id ? self::query()->find($current->parent_id) : null;
        }

        return false;
    }

    /** "上層 › 中層 › 名稱" for admin lists. */
    public function path(): string
    {
        return ($this->parent ? $this->parent->path().' › ' : '').$this->name;
    }

    /**
     * @return BelongsTo<ArticleCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ArticleCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Article, $this>
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }
}
