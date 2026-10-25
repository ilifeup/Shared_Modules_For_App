<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\ArticleHtml;

/**
 * S8 M05-A Markdown import (.md with optional YAML front matter).
 * - A new file becomes a draft (front matter: title, author, cover, summary, categories).
 * - Same title as an existing article: nothing is changed unless $updateExisting is
 *   true, and then ONLY the title and the body are replaced.
 */
final class ArticleImporter
{
    public function __construct(private readonly ArticleHtml $html) {}

    /**
     * @return array{status: 'created'|'updated'|'duplicate', article: Article|null, existing: Article|null, unknown_categories: list<string>}
     */
    public function import(string $filename, string $markdown, bool $updateExisting, User $actor): array
    {
        ['meta' => $meta, 'html' => $html] = $this->html->fromMarkdown($markdown);
        $title = $this->title($meta, $markdown, $filename);

        $existing = Article::query()->where('title', $title)->first();

        if ($existing !== null && ! $updateExisting) {
            return ['status' => 'duplicate', 'article' => null, 'existing' => $existing, 'unknown_categories' => []];
        }

        if ($existing !== null) {
            $existing->forceFill(['title' => $title, 'body_html' => $html])->save();
            AuditEvent::record('article.imported_update', $actor->id, $existing);

            return ['status' => 'updated', 'article' => $existing, 'existing' => $existing, 'unknown_categories' => []];
        }

        $cover = is_string($meta['cover'] ?? null) && preg_match('#^https?://#i', $meta['cover']) === 1 ? $meta['cover'] : null;
        $article = new Article([
            'title' => $title,
            'body_html' => $html,
            'summary' => is_string($meta['summary'] ?? null) ? mb_substr($meta['summary'], 0, 1000) : null,
            'author_name' => is_string($meta['author'] ?? null) && $meta['author'] !== '' ? mb_substr($meta['author'], 0, 80) : $actor->name,
            'cover_url' => $cover,
            'status' => Article::STATUS_DRAFT,
            'visibility' => Article::VISIBILITY_PUBLIC,
        ]);
        $article->created_by = $actor->id;
        $article->save();

        $names = array_values(array_filter((array) ($meta['categories'] ?? []), 'is_string'));
        $found = ArticleCategory::query()->whereIn('name', $names)->get();
        $article->categories()->sync($found->pluck('id')->all());

        AuditEvent::record('article.imported', $actor->id, $article);

        return [
            'status' => 'created',
            'article' => $article,
            'existing' => null,
            'unknown_categories' => array_values(array_diff($names, $found->pluck('name')->all())),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function title(array $meta, string $markdown, string $filename): string
    {
        if (is_string($meta['title'] ?? null) && trim($meta['title']) !== '') {
            return mb_substr(trim($meta['title']), 0, 255);
        }

        if (preg_match('/^#\s+(.+)$/m', $markdown, $m) === 1) {
            return mb_substr(trim($m[1]), 0, 255);
        }

        return mb_substr(pathinfo($filename, PATHINFO_FILENAME) ?: '未命名文章', 0, 255);
    }
}
