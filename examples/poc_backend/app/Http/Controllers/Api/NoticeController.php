<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NoticeResource;
use App\Models\Notice;
use App\Models\NoticeCategory;
use App\Models\NoticeRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NoticeController extends Controller
{
    /**
     * GET /api/v1/notices — visible notices for members: pinned first, then newest.
     * Optional ?category={id}. Each item has is_read; meta.unread_count counts all visible unread.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;
        $query = $this->filtered($request, Notice::query()->visibleToMembers())
            ->with('category')
            ->withExists(['reads as is_read' => fn (Builder $q) => $q->where('user_id', $userId)]);

        $unread = Notice::query()->visibleToMembers()
            ->whereDoesntHave('reads', fn (Builder $q) => $q->where('user_id', $userId))
            ->count();

        return NoticeResource::collection($query->ordered()->paginate(20))
            ->additional(['meta' => ['unread_count' => $unread]]);
    }

    /**
     * GET /api/v1/notices/{id} — drafts, withdrawn, future and ended notices are not found.
     */
    public function show(Request $request, int $id): NoticeResource
    {
        $userId = $request->user()->id;

        return new NoticeResource(
            Notice::query()->visibleToMembers()->with('category')
                ->withExists(['reads as is_read' => fn (Builder $q) => $q->where('user_id', $userId)])
                ->findOrFail($id)
        );
    }

    /**
     * POST /api/v1/notices/{id}/read — idempotent; repeated calls keep one row.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $notice = Notice::query()->visibleToMembers()->findOrFail($id);

        NoticeRead::query()->insertOrIgnore([
            'notice_id' => $notice->id,
            'user_id' => $request->user()->id,
            'read_at' => now(),
        ]);

        return new JsonResponse(['data' => ['id' => (string) $notice->id, 'is_read' => true]]);
    }

    /**
     * GET /api/v1/public/notices — public-audience notices, no login required.
     */
    public function publicIndex(Request $request): AnonymousResourceCollection
    {
        return NoticeResource::collection(
            $this->filtered($request, Notice::query()->visibleToPublic())->with('category')->ordered()->paginate(20)
        );
    }

    /**
     * GET /api/v1/public/notices/{id}
     */
    public function publicShow(int $id): NoticeResource
    {
        return new NoticeResource(Notice::query()->visibleToPublic()->with('category')->findOrFail($id));
    }

    /**
     * GET /api/v1/notice-categories — active categories for filtering.
     */
    public function categories(): JsonResponse
    {
        return new JsonResponse([
            'data' => NoticeCategory::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn (NoticeCategory $c) => ['id' => (string) $c->id, 'name' => $c->name])->all(),
        ]);
    }

    /**
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    private function filtered(Request $request, Builder $query): Builder
    {
        $request->validate(['category' => ['sometimes', 'integer', 'min:1']]);

        return $query->when($request->filled('category'), fn (Builder $q) => $q->where('notice_category_id', (int) $request->input('category')));
    }
}
