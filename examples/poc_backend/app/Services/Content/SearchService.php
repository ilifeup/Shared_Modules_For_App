<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\ArticleAccess;
use App\Support\ContentRegistry;
use App\Support\Settings;
use App\Support\VerificationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * S9 M19 search across the App's own content (D01) with the existing Laravel/MySQL
 * stack (D24): keyword relevance (title 3, summary 2, body 1; every keyword must
 * match — AND), filters combine with sorting (D16), 20 per page (D15). Visibility
 * comes from the content's own rules (ArticleAccess / event audience). Search
 * terms are not stored (D06).
 */
class SearchService
{
    public const PER_PAGE = 20;

    /** Upper bound of candidates per type before ranking (MVP data volume). */
    public const MAX_CANDIDATES = 2000;

    public function __construct(
        private readonly Settings $settings,
        private readonly ArticleAccess $articles,
        private readonly VerificationPolicy $policy,
        private readonly ContentRegistry $registry,
        private readonly TagService $tags,
    ) {}

    /**
     * @return list<string>
     */
    public function enabledTypes(): array
    {
        return array_values(array_intersect(['article', 'event'], Settings::csv($this->settings->string('content_search_types'))));
    }

    /**
     * @param  array{q?: string|null, type?: string|null, tags?: list<int>, article_category_id?: int|null, event_category_id?: int|null, from?: Carbon|null, to?: Carbon|null, location?: string|null, sort?: string, page?: int}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int}
     */
    public function search(array $filters, ?User $viewer): array
    {
        $keywords = self::keywords((string) ($filters['q'] ?? ''));
        $types = $this->enabledTypes();
        if (! empty($filters['type'])) {
            $types = array_values(array_intersect($types, [$filters['type']]));
        }
        if (! empty($filters['article_category_id'])) {
            $types = array_values(array_intersect($types, ['article']));
        }
        if (! empty($filters['event_category_id']) || ! empty($filters['location'])) {
            $types = array_values(array_intersect($types, ['event']));
        }

        $tagIds = array_values(array_unique(array_map('intval', $filters['tags'] ?? [])));
        $candidates = [];
        foreach ($types as $type) {
            $query = $type === 'article' ? $this->articleQuery($viewer, $filters) : $this->eventQuery($viewer, $filters);
            [$titleCol, $summaryCol, $bodyCol, $dateCol] = $type === 'article'
                ? ['title', 'summary', 'body_html', 'published_at']
                : ['title', 'location', 'description', 'starts_at'];

            foreach ($keywords as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where($titleCol, 'like', $like)->orWhere($summaryCol, 'like', $like)->orWhere($bodyCol, 'like', $like)
                    ->when($type === 'event', fn (Builder $e) => $e->orWhere('floor', 'like', $like)->orWhere('studio', 'like', $like)));
            }
            foreach ($tagIds as $tagId) { // D27: AND across selected tags
                $query->whereExists(fn ($sub) => $sub->from('taggables')->where('tag_id', $tagId)->where('taggable_type', $type)
                    ->whereColumn('taggable_id', ($type === 'article' ? 'articles' : 'calendar_events').'.id')
                    ->whereExists(fn ($t) => $t->from('tags')->whereColumn('tags.id', 'taggables.tag_id')->where('is_active', true)));
            }

            $scoreSql = [];
            $bindings = [];
            foreach ($keywords as $word) {
                $like = '%'.addcslashes($word, '%_\\').'%';
                $scoreSql[] = "(CASE WHEN {$titleCol} LIKE ? THEN 3 ELSE 0 END + CASE WHEN {$summaryCol} LIKE ? THEN 2 ELSE 0 END + CASE WHEN {$bodyCol} LIKE ? THEN 1 ELSE 0 END)";
                array_push($bindings, $like, $like, $like);
            }

            $rows = $query->select(['id', $dateCol.' as sort_date'])
                ->selectRaw(($scoreSql === [] ? '0' : implode(' + ', $scoreSql)).' as score', $bindings)
                ->limit(self::MAX_CANDIDATES)->get();
            foreach ($rows as $row) {
                $candidates[] = ['type' => $type, 'id' => (int) $row->id, 'score' => (int) $row->score, 'date' => (string) $row->sort_date];
            }
        }

        $sort = $filters['sort'] ?? 'relevance';
        usort($candidates, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'oldest' => [$a['date'], $a['type'], $a['id']] <=> [$b['date'], $b['type'], $b['id']],
                'newest' => [$b['date'], $b['type'], $b['id']] <=> [$a['date'], $a['type'], $a['id']],
                // Relevance, then newest, then a stable key (no duplicates across pages).
                default => [$b['score'], $b['date'], $a['type'], $b['id']] <=> [$a['score'], $a['date'], $b['type'], $a['id']],
            };
        });

        $total = count($candidates);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $slice = array_slice($candidates, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        return [
            'items' => array_map(fn (array $c): array => $this->item($c['type'], $c['id'], $viewer), $slice),
            'total' => $total,
            'page' => $page,
            'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * Whitespace separated keywords (max 5, each max 50 characters).
     *
     * @return list<string>
     */
    public static function keywords(string $q): array
    {
        $words = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique(array_map(fn (string $w): string => mb_substr($w, 0, 50), $words))), 0, 5);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $type, int $id, ?User $viewer): array
    {
        $content = $this->registry->find($type, $id);

        return $this->registry->summary($type, $content) + [
            'tags' => $this->tags->tagsOf($type, $id)->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'color' => $t->displayColor()])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Article>
     */
    private function articleQuery(?User $viewer, array $filters): Builder
    {
        $query = $this->articles->scope(Article::query()->live(), $viewer);
        if (! empty($filters['article_category_id'])) {
            $query->whereHas('categories', fn (Builder $c) => $c->whereKey((int) $filters['article_category_id']));
        }
        if (! empty($filters['from'])) {
            $query->where('published_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('published_at', '<', $filters['to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CalendarEvent>
     */
    private function eventQuery(?User $viewer, array $filters): Builder
    {
        $member = $viewer !== null && $viewer->is_active && $this->policy->isSatisfiedBy($viewer);
        $query = CalendarEvent::query()->where('status', CalendarEvent::STATUS_PUBLISHED)
            ->when(! $member, fn (Builder $q) => $q->where('audience', CalendarEvent::AUDIENCE_PUBLIC));
        if (! empty($filters['event_category_id'])) {
            $query->where('event_category_id', (int) $filters['event_category_id']);
        }
        if (! empty($filters['location'])) { // D11: district or venue name, no GPS distance
            $like = '%'.addcslashes((string) $filters['location'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('location', 'like', $like)->orWhere('floor', 'like', $like)->orWhere('studio', 'like', $like));
        }
        if (! empty($filters['from'])) {
            $query->where('starts_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('starts_at', '<', $filters['to']);
        }

        return $query;
    }
}
