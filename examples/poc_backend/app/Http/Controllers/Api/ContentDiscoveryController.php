<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ArticleAccess;
use App\Services\Content\BookmarkService;
use App\Services\Content\SearchService;
use App\Services\Content\TagService;
use App\Support\Api\ApiException;
use App\Support\ContentRegistry;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * S9 M16 public tags and M19 search (optional login) + bookmarks (member).
 */
class ContentDiscoveryController extends Controller
{
    public function __construct(
        private readonly TagService $tags,
        private readonly SearchService $search,
        private readonly BookmarkService $bookmarks,
        private readonly ArticleAccess $access,
        private readonly Settings $settings,
        private readonly ContentRegistry $registry,
    ) {}

    /**
     * GET /api/v1/tags?q=&sort=order|popular — active tags with public content (D13, D14, D17).
     */
    public function tags(Request $request): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:50'], 'sort' => ['sometimes', 'in:order,popular']]);

        return new JsonResponse(['data' => $this->tags->publicTags($request->input('q'), $request->input('sort', 'order'))]);
    }

    /**
     * GET /api/v1/search?q=&type=&tags[]=&article_category_id=&event_category_id=&from=&to=&location=&sort=&page=
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'type' => ['sometimes', 'nullable', 'in:article,event'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['integer', 'min:1'],
            'article_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'event_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:relevance,newest,oldest'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $viewer = $this->access->viewer($request);

        $result = $this->search->search([
            'q' => $data['q'] ?? null,
            'type' => $data['type'] ?? null,
            'tags' => $data['tags'] ?? [],
            'article_category_id' => $data['article_category_id'] ?? null,
            'event_category_id' => $data['event_category_id'] ?? null,
            'from' => isset($data['from']) ? Carbon::parse($data['from'])->utc() : null,
            'to' => isset($data['to']) ? Carbon::parse($data['to'])->utc() : null,
            'location' => $data['location'] ?? null,
            'sort' => $data['sort'] ?? 'relevance',
            'page' => (int) ($data['page'] ?? 1),
        ], $viewer);

        if ($viewer !== null && $result['items'] !== []) {
            $status = $this->bookmarks->status($viewer, array_map(fn (array $i) => ['content_type' => $i['content_type'], 'content_id' => (int) $i['content_id']], $result['items']));
            $result['items'] = array_map(fn (array $i) => $i + ['bookmarked' => $status[$i['content_type'].':'.$i['content_id']] ?? false], $result['items']);
        }

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => ['current_page' => $result['page'], 'last_page' => $result['last_page'], 'total' => $result['total'], 'per_page' => SearchService::PER_PAGE, 'types' => $this->search->enabledTypes()],
        ]);
    }

    /**
     * GET /api/v1/me/bookmarks?type=&page=
     */
    public function bookmarks(Request $request): JsonResponse
    {
        $request->validate(['type' => ['sometimes', 'nullable', 'in:article,event,notice'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $result = $this->bookmarks->list($request->user(), $request->input('type'), (int) $request->input('page', 1));

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => ['current_page' => $result['page'], 'last_page' => $result['last_page'], 'total' => $result['total'], 'count' => $result['count'], 'limit' => $result['limit']],
        ]);
    }

    /**
     * POST /api/v1/me/bookmarks {content_type, content_id} (idempotent)
     */
    public function addBookmark(Request $request): JsonResponse
    {
        $data = $request->validate(['content_type' => ['required', 'in:article,event,notice'], 'content_id' => ['required', 'integer', 'min:1']]);
        $bookmark = $this->bookmarks->add($request->user(), $data['content_type'], (int) $data['content_id']);

        return new JsonResponse(['data' => [
            'content_type' => $bookmark->content_type,
            'content_id' => (string) $bookmark->content_id,
            'bookmarked' => true,
            'bookmarked_at' => $bookmark->created_at?->toIso8601ZuluString(),
        ]], $bookmark->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * DELETE /api/v1/me/bookmarks/{type}/{id}
     */
    public function removeBookmark(Request $request, string $type, int $id): JsonResponse
    {
        if (! ContentRegistry::valid($type)) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此收藏。');
        }
        $this->bookmarks->remove($request->user(), $type, $id);

        return new JsonResponse(['data' => ['content_type' => $type, 'content_id' => (string) $id, 'bookmarked' => false]]);
    }

    /**
     * DELETE /api/v1/me/bookmarks — clear all (the App confirms twice, D26).
     */
    public function clearBookmarks(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => ['deleted' => $this->bookmarks->clear($request->user())]]);
    }

    /**
     * GET /api/v1/content/{type}/{id}/share — share URL when the content may be shared (M14-D23).
     */
    public function share(Request $request, string $type, int $id): JsonResponse
    {
        if (! ContentRegistry::valid($type)) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此內容。');
        }
        $content = $this->registry->find($type, $id);
        if (! $this->registry->canView($type, $content, $this->access->viewer($request))) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此內容。');
        }
        $enabled = $this->settings->allows('content_share_types', $type);

        return new JsonResponse(['data' => [
            'content_type' => $type,
            'content_id' => (string) $id,
            'shareable' => $enabled,
            'url' => $enabled ? $this->registry->webUrl($type, $id) : null,
            'is_public' => $this->registry->isPublic($type, $content),
        ]]);
    }
}
