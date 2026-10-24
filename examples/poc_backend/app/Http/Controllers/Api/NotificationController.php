<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * S4 M04: the member's own notifications only (queried through the member's
 * relation, so another member's id is simply not found).
 */
class NotificationController extends Controller
{
    /**
     * GET /api/v1/me/notifications — newest first, 20 per page; meta.unread_count.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $user = $request->user();
        $page = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->item($n))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * GET /api/v1/me/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    /**
     * GET /api/v1/me/notifications/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->item($this->find($request, $id))]);
    }

    /**
     * POST /api/v1/me/notifications/{id}/read — idempotent (keeps the first read time).
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->find($request, $id);
        $notification->markAsRead();

        return new JsonResponse([
            'data' => $this->item($notification->refresh()),
            'meta' => ['unread_count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * POST /api/v1/me/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return new JsonResponse(['data' => ['updated' => $updated, 'unread_count' => 0]]);
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function item(DatabaseNotification $n): array
    {
        $data = is_array($n->data) ? $n->data : [];
        $actionType = $data['action_type'] ?? null;

        return [
            'id' => (string) $n->id,
            'type' => (string) ($data['type'] ?? 'system'),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'action' => $actionType === null ? null : [
                'type' => (string) $actionType,
                'id' => isset($data['action_id']) ? (string) $data['action_id'] : null,
            ],
            'is_read' => $n->read_at !== null,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at?->toIso8601ZuluString(),
        ];
    }
}
