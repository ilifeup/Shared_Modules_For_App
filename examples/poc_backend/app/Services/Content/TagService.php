<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\Tag;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * S9 M16 tag data, relations and visibility. Searching content stays in M19 (D23).
 */
class TagService
{
    public const TAGGABLE_TYPES = ['article', 'event'];

    public function __construct(private readonly Settings $settings) {}

    public function enabledFor(string $type): bool
    {
        return in_array($type, self::TAGGABLE_TYPES, true) && $this->settings->allows('content_tag_types', $type);
    }

    public function create(string $name, ?string $color, int $sort, User $actor): Tag
    {
        $this->assertUnique($name);
        $tag = Tag::query()->create(['name' => $name, 'color' => self::color($color), 'sort' => $sort, 'is_active' => true]);
        AuditEvent::record('tag.created', $actor->id, $tag, ['name' => $tag->name]);

        return $tag;
    }

    /**
     * @param  array{name?: string, color?: string|null, sort?: int, is_active?: bool}  $changes
     */
    public function update(Tag $tag, array $changes, User $actor): Tag
    {
        if (isset($changes['name']) && Tag::normalize($changes['name']) !== $tag->normalized_name) {
            $this->assertUnique($changes['name'], $tag->id);
        }
        if (array_key_exists('color', $changes)) {
            $changes['color'] = self::color($changes['color']);
        }

        $before = $tag->only(['name', 'color', 'is_active', 'sort']);
        $tag->fill($changes)->save();
        if ($before['name'] !== $tag->name) {
            AuditEvent::record('tag.renamed', $actor->id, $tag, ['from' => $before['name'], 'to' => $tag->name]);
        }
        if ($before['is_active'] !== $tag->is_active) {
            AuditEvent::record($tag->is_active ? 'tag.enabled' : 'tag.disabled', $actor->id, $tag);
        }

        return $tag;
    }

    /**
     * Moves the source's relations to the target (no duplicates) and removes the source (D10).
     *
     * @return int relations moved
     */
    public function merge(Tag $source, Tag $target, User $actor): int
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target' => ['來源與目標標籤不可相同。']]);
        }

        $moved = DB::transaction(function () use ($source, $target): int {
            $rows = DB::table('taggables')->where('tag_id', $source->id)->lockForUpdate()->get();
            $moved = 0;
            foreach ($rows as $row) {
                $exists = DB::table('taggables')->where('tag_id', $target->id)->where('taggable_type', $row->taggable_type)->where('taggable_id', $row->taggable_id)->exists();
                if (! $exists) {
                    DB::table('taggables')->insert(['tag_id' => $target->id, 'taggable_type' => $row->taggable_type, 'taggable_id' => $row->taggable_id, 'created_at' => now()]);
                    $moved++;
                }
            }
            DB::table('taggables')->where('tag_id', $source->id)->delete();
            $source->delete();

            return $moved;
        });

        AuditEvent::record('tag.merged', $actor->id, $target, ['source' => $source->name, 'source_id' => $source->id, 'moved' => $moved]);

        return $moved;
    }

    /**
     * Preview for the delete confirmation (D28).
     *
     * @param  list<int>  $ids
     * @return array{tags: int, relations: int, names: list<string>}
     */
    public function deletePreview(array $ids): array
    {
        $tags = Tag::query()->whereIn('id', $ids)->get();

        return [
            'tags' => $tags->count(),
            'relations' => DB::table('taggables')->whereIn('tag_id', $ids)->count(),
            'names' => $tags->pluck('name')->all(),
        ];
    }

    /**
     * Deletes tags and their relations only — never the content (D09).
     *
     * @param  list<int>  $ids
     */
    public function delete(array $ids, User $actor): array
    {
        $preview = $this->deletePreview($ids);
        DB::transaction(function () use ($ids): void {
            DB::table('taggables')->whereIn('tag_id', $ids)->delete();
            Tag::query()->whereIn('id', $ids)->delete();
        });
        AuditEvent::record('tag.deleted', $actor->id, null, $preview);

        return $preview;
    }

    /**
     * @param  list<int>  $ids
     */
    public function setActive(array $ids, bool $active, User $actor): int
    {
        $count = Tag::query()->whereIn('id', $ids)->update(['is_active' => $active, 'updated_at' => now()]);
        AuditEvent::record($active ? 'tag.bulk_enabled' : 'tag.bulk_disabled', $actor->id, null, ['ids' => array_values($ids)]);

        return $count;
    }

    /**
     * Replaces the tags of one content item; max 10 enforced in a transaction with the
     * content row locked (D22).
     *
     * @param  list<int>  $tagIds
     */
    public function sync(string $type, int $id, array $tagIds, ?User $actor = null): void
    {
        if (! in_array($type, self::TAGGABLE_TYPES, true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', '此內容類型不使用標籤。');
        }
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) > Tag::MAX_PER_CONTENT) {
            throw new ApiException(422, 'TAG_LIMIT_EXCEEDED', '每筆內容最多 '.Tag::MAX_PER_CONTENT.' 個標籤。', ['tags' => ['每筆內容最多 '.Tag::MAX_PER_CONTENT.' 個標籤。']]);
        }

        DB::transaction(function () use ($type, $id, $tagIds): void {
            $model = $type === 'article' ? Article::query() : CalendarEvent::query();
            $model->whereKey($id)->lockForUpdate()->firstOrFail();

            $valid = Tag::query()->whereIn('id', $tagIds)->pluck('id')->all();
            DB::table('taggables')->where('taggable_type', $type)->where('taggable_id', $id)->whereNotIn('tag_id', $valid)->delete();
            $existing = DB::table('taggables')->where('taggable_type', $type)->where('taggable_id', $id)->pluck('tag_id')->all();
            foreach (array_diff($valid, $existing) as $tagId) {
                DB::table('taggables')->insert(['tag_id' => $tagId, 'taggable_type' => $type, 'taggable_id' => $id, 'created_at' => now()]);
            }
        });

        if ($actor !== null) {
            AuditEvent::record('tag.content_synced', $actor->id, null, ['content_type' => $type, 'content_id' => $id, 'tags' => $tagIds]);
        }
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tagsOf(string $type, int $id, bool $activeOnly = true): Collection
    {
        return Tag::query()
            ->whereIn('id', DB::table('taggables')->where('taggable_type', $type)->where('taggable_id', $id)->select('tag_id'))
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * Public tags: active and with at least one publicly visible content item (D17,
     * D26), counted on publicly visible content only (D12, D14). Fresh per request (D24).
     *
     * @return list<array{id: string, name: string, color: string, count: int}>
     */
    public function publicTags(?string $search = null, string $sort = 'order'): array
    {
        $tags = Tag::query()->where('is_active', true)
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->where('normalized_name', 'like', '%'.addcslashes(Tag::normalize((string) $search), '%_\\').'%'))
            ->get();

        $counts = $this->publicCounts($tags->pluck('id')->all());
        $list = $tags->filter(fn (Tag $t): bool => ($counts[$t->id] ?? 0) > 0)
            ->map(fn (Tag $t): array => ['id' => (string) $t->id, 'name' => $t->name, 'color' => $t->displayColor(), 'count' => $counts[$t->id], 'sort' => $t->sort])
            ->values();

        $list = $sort === 'popular'
            ? $list->sortBy([['count', 'desc'], ['sort', 'asc'], ['id', 'asc']])
            : $list->sortBy([['sort', 'asc'], ['id', 'asc']]);

        return $list->map(fn (array $t): array => array_diff_key($t, ['sort' => 1]))->values()->all();
    }

    /**
     * @param  list<int>  $tagIds
     * @return array<int, int>
     */
    private function publicCounts(array $tagIds): array
    {
        if ($tagIds === []) {
            return [];
        }

        $counts = [];
        $rows = DB::table('taggables')->whereIn('tag_id', $tagIds)->get();
        foreach ($rows->groupBy('taggable_type') as $type => $group) {
            if (! $this->enabledFor($type)) {
                continue;
            }
            $public = $this->publicIds($type, $group->pluck('taggable_id')->unique()->all());
            foreach ($group as $row) {
                if (isset($public[$row->taggable_id])) {
                    $counts[$row->tag_id] = ($counts[$row->tag_id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function publicIds(string $type, array $ids): array
    {
        $visible = match ($type) {
            'article' => Article::query()->live()->where('visibility', Article::VISIBILITY_PUBLIC)->whereIn('id', $ids)->pluck('id'),
            'event' => CalendarEvent::query()->visibleToPublic()->whereIn('id', $ids)->pluck('id'),
            default => collect(),
        };

        return $visible->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    private function assertUnique(string $name, ?int $ignore = null): void
    {
        $normalized = Tag::normalize($name);
        if ($normalized === '' || mb_strlen($normalized) > 50) {
            throw ValidationException::withMessages(['name' => ['標籤名稱需為 1–50 字。']]);
        }
        if (Tag::query()->where('normalized_name', $normalized)->when($ignore, fn (Builder $q) => $q->whereKeyNot($ignore))->exists()) {
            throw ValidationException::withMessages(['name' => ['已有相同名稱的標籤。']]);
        }
    }

    /** Only #RRGGBB is stored (no CSS injection); null = automatic colour. */
    public static function color(?string $color): ?string
    {
        if ($color === null || trim($color) === '') {
            return null;
        }
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', trim($color)) !== 1) {
            throw ValidationException::withMessages(['color' => ['顏色格式為 #RRGGBB。']]);
        }

        return strtoupper(trim($color));
    }
}
