<?php

namespace App\Services\Content;

use App\Models\AuditEvent;
use App\Models\Bookmark;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\ContentRegistry;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * S9 M19 bookmarks of the signed-in member (D04, D05, D13, D20–D26).
 * The 1,000 limit includes unavailable placeholders and is enforced under a row lock
 * on the member, so two devices cannot exceed it together (G10).
 */
class BookmarkService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContentRegistry $registry,
    ) {}

    /**
     * Adds (idempotent). The content must exist and be visible now.
     */
    public function add(User $member, string $type, int $id): Bookmark
    {
        if (! ContentRegistry::valid($type) || ! $this->settings->allows('content_bookmark_types', $type)) {
            throw new ApiException(422, 'BOOKMARK_TYPE_DISABLED', '此內容類型無法收藏。');
        }
        $content = $this->registry->find($type, $id);
        if (! $this->registry->canView($type, $content, $member)) {
            throw new ApiException(404, 'NOT_FOUND', '找不到可收藏的內容。');
        }

        return DB::transaction(function () use ($member, $type, $id): Bookmark {
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            $existing = Bookmark::query()->where('user_id', $member->id)->where('content_type', $type)->where('content_id', $id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if (Bookmark::query()->where('user_id', $member->id)->count() >= Bookmark::MAX_PER_MEMBER) {
                throw new ApiException(409, 'BOOKMARK_LIMIT_REACHED', '收藏已達 '.Bookmark::MAX_PER_MEMBER.' 筆上限（含已無法開啟的收藏），請先取消部分收藏。');
            }

            return Bookmark::query()->create(['user_id' => $member->id, 'content_type' => $type, 'content_id' => $id, 'created_at' => now()]);
        });
    }

    public function remove(User $member, string $type, int $id): bool
    {
        return Bookmark::query()->where('user_id', $member->id)->where('content_type', $type)->where('content_id', $id)->delete() > 0;
    }

    /** Clears all bookmarks of this member in this App (D26; the App asks twice). */
    public function clear(User $member): int
    {
        $count = Bookmark::query()->where('user_id', $member->id)->delete();
        AuditEvent::record('bookmark.cleared', $member->id, null, ['count' => $count]);

        return $count;
    }

    /**
     * Newest first (D17). Unavailable content (removed, unpublished or no longer
     * permitted) shows a placeholder without title or details (D10, D14, D21).
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int, count: int, limit: int}
     */
    public function list(User $member, ?string $type, int $page): array
    {
        $query = Bookmark::query()->where('user_id', $member->id)
            ->when($type !== null, fn ($q) => $q->where('content_type', $type))
            ->orderByDesc('created_at')->orderByDesc('id');

        $paginator = $query->paginate(20, ['*'], 'page', $page);
        $items = collect($paginator->items())->map(function (Bookmark $b) use ($member): array {
            $content = $this->registry->find($b->content_type, (int) $b->content_id);
            $base = ['content_type' => $b->content_type, 'content_id' => (string) $b->content_id, 'bookmarked_at' => $b->created_at?->toIso8601ZuluString()];
            if (! $this->registry->canView($b->content_type, $content, $member)) {
                return $base + ['available' => false, 'item' => null];
            }

            return $base + ['available' => true, 'item' => $this->registry->summary($b->content_type, $content)];
        })->all();

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'count' => Bookmark::query()->where('user_id', $member->id)->count(),
            'limit' => Bookmark::MAX_PER_MEMBER,
        ];
    }

    /**
     * @param  list<array{content_type: string, content_id: int}>  $refs
     * @return array<string, bool> "type:id" => bookmarked
     */
    public function status(User $member, array $refs): array
    {
        $result = [];
        foreach ($refs as $ref) {
            $result[$ref['content_type'].':'.$ref['content_id']] = false;
        }
        $rows = Bookmark::query()->where('user_id', $member->id)->get(['content_type', 'content_id']);
        foreach ($rows as $row) {
            $key = $row->content_type.':'.$row->content_id;
            if (array_key_exists($key, $result)) {
                $result[$key] = true;
            }
        }

        return $result;
    }
}
