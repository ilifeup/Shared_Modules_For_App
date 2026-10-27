<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleComment;
use App\Models\ArticleLike;
use App\Models\Bookmark;
use App\Models\MemberVerification;
use App\Models\User;
use App\Services\ArticleAccess;
use App\Services\ArticleInteractionService;
use App\Services\Content\TagService;
use App\Support\Api\ApiException;
use App\Support\ArticleHtml;
use App\Support\ContentRegistry;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S8 M05 member / public article API. Read permission is decided here on the
 * server (ArticleAccess) for every list, detail, cover, like list and comment list.
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleAccess $access,
        private readonly ArticleInteractionService $interactions,
        private readonly ArticleHtml $html,
        private readonly Settings $settings,
        private readonly ContentRegistry $registry,
        private readonly TagService $tags,
    ) {}

    /**
     * GET /api/v1/articles?category=&sort=latest|popular&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'in:latest,popular'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $this->readable($request)
            ->when($request->filled('category'), fn (Builder $q) => $q->whereHas('categories', fn (Builder $c) => $c->whereKey((int) $request->input('category'))));

        $request->input('sort') === 'popular'
            ? $query->orderByPopularity($this->settings->int('article_like_weight'))
            : $query->orderByDesc('published_at')->orderByDesc('id');

        $page = $query->paginate(20);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (Article $a) => $this->summary($a))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * GET /api/v1/articles/featured — manual order (featured_order, then newest).
     */
    public function featured(Request $request): JsonResponse
    {
        $items = $this->readable($request)->where('is_featured', true)
            ->orderBy('featured_order')->orderByDesc('published_at')->limit(10)->get();

        return new JsonResponse(['data' => $items->map(fn (Article $a) => $this->summary($a))->all()]);
    }

    /**
     * GET /api/v1/articles/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $this->access->viewer($request);
        $article = $this->live($id);
        $this->authorizeRead($article, $viewer);

        $this->interactions->recordView($article, $viewer, (string) $request->ip(), (string) $request->userAgent());
        $article->refresh()->load('categories');

        return new JsonResponse(['data' => $this->summary($article) + [
            'body_html' => $article->body_html,
            'blocks' => $this->html->toBlocks($article->body_html),
            // S9 M14: fixed content id URL (title changes never change the link).
            'share_url' => $this->registry->webUrl('article', (int) $article->id),
            'shareable' => $this->settings->allows('content_share_types', 'article'),
            'tags' => $this->tags->tagsOf('article', (int) $article->id)->map(fn ($t) => ['id' => (string) $t->id, 'name' => $t->name, 'color' => $t->displayColor()])->values()->all(),
            'bookmarked' => $viewer !== null && Bookmark::query()->where('user_id', $viewer->id)->where('content_type', 'article')->where('content_id', $article->id)->exists(),
            'liked_by_me' => $viewer !== null && ArticleLike::query()->where('article_id', $article->id)->where('user_id', $viewer->id)->exists(),
            'comment_count' => $article->comments()->where('status', ArticleComment::STATUS_VISIBLE)->count(),
            // Likes, comments and reports need a logged-in member who satisfies the policy.
            'can_interact' => $this->access->credentials($viewer) !== null,
        ]]);
    }

    /**
     * GET /api/v1/articles/{id}/cover — uploaded cover image (same permission as the article).
     */
    public function cover(Request $request, int $id): StreamedResponse
    {
        $article = $this->live($id);
        $this->authorizeRead($article, $this->access->viewer($request));

        if ($article->cover_path === null || ! Storage::disk('local')->exists($article->cover_path)) {
            throw new ApiException(404, 'NOT_FOUND', '沒有封面圖片。');
        }

        return Storage::disk('local')->response($article->cover_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    /**
     * GET /api/v1/article-categories — tree (up to three levels).
     */
    public function categories(): JsonResponse
    {
        $all = ArticleCategory::query()->orderBy('sort')->orderBy('id')->get();
        $tree = function (?int $parent) use (&$tree, $all): array {
            return $all->where('parent_id', $parent)->values()->map(fn (ArticleCategory $c) => [
                'id' => (string) $c->id,
                'name' => $c->name,
                'children' => $tree($c->id),
            ])->all();
        };

        return new JsonResponse(['data' => $tree(null)]);
    }

    /**
     * GET /api/v1/articles/{id}/likes — total and the nicknames of members who chose
     * to be listed (others are only counted).
     */
    public function likes(Request $request, int $id): JsonResponse
    {
        $article = $this->live($id);
        $this->authorizeRead($article, $this->access->viewer($request));

        $listedQuery = ArticleLike::query()->where('article_id', $article->id)
            ->whereHas('user', fn (Builder $u) => $u->where('show_in_like_list', true)->whereNotNull('nickname'));
        $listedTotal = (clone $listedQuery)->count();
        $listed = $listedQuery->with('user:id,nickname')->latest('id')->limit(200)->get();

        $total = ArticleLike::query()->where('article_id', $article->id)->count();

        return new JsonResponse(['data' => [
            'total' => $total,
            'listed' => $listed->map(fn (ArticleLike $l) => ['nickname' => $l->user->nickname])->all(),
            'unlisted_count' => max(0, $total - $listedTotal),
        ]]);
    }

    /**
     * POST /api/v1/articles/{id}/like  (idempotent)
     */
    public function like(Request $request, int $id): JsonResponse
    {
        $article = $this->interactive($request, $id);
        $this->interactions->like($article, $request->user());

        return $this->likeState($article, $request->user());
    }

    /**
     * DELETE /api/v1/articles/{id}/like  (idempotent)
     */
    public function unlike(Request $request, int $id): JsonResponse
    {
        $article = $this->interactive($request, $id);
        $this->interactions->unlike($article, $request->user());

        return $this->likeState($article, $request->user());
    }

    /**
     * GET /api/v1/articles/{id}/comments — visible comments, newest first.
     */
    public function comments(Request $request, int $id): JsonResponse
    {
        $viewer = $this->access->viewer($request);
        $article = $this->live($id);
        $this->authorizeRead($article, $viewer);

        $page = $article->comments()->where('status', ArticleComment::STATUS_VISIBLE)
            ->with('user:id,nickname')->withCount(['attachments as attachment_count' => fn (Builder $q) => $q->whereNull('removed_at')])->orderByDesc('created_at')->orderByDesc('id')->paginate(20);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (ArticleComment $c) => $this->comment($c, $viewer))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * POST /api/v1/articles/{id}/comments
     */
    public function storeComment(Request $request, int $id): JsonResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $article = $this->interactive($request, $id);
        $comment = $this->interactions->comment($article, $request->user(), (string) $request->input('body'));

        return new JsonResponse(['data' => $this->comment($comment->load('user:id,nickname'), $request->user())], 201);
    }

    /**
     * POST /api/v1/article-comments/{id}/reports
     */
    public function report(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $comment = ArticleComment::query()->where('status', ArticleComment::STATUS_VISIBLE)->findOrFail($id);
        $this->interactive($request, $comment->article_id);
        $this->interactions->report($comment, $request->user(), (string) $request->input('reason'), $request->input('note'));

        return new JsonResponse(['data' => ['reported' => true]], 201);
    }

    /**
     * GET /api/v1/article-report-reasons
     */
    public function reportReasons(): JsonResponse
    {
        return new JsonResponse(['data' => collect((array) config('articles.report_reasons', []))
            ->map(fn (string $label, string $code) => ['code' => $code, 'label' => $label])->values()->all()]);
    }

    /**
     * @return Builder<Article>
     */
    private function readable(Request $request): Builder
    {
        return $this->access->scope(Article::query()->live()->with('categories'), $this->access->viewer($request));
    }

    private function live(int $id): Article
    {
        $article = Article::query()->with('accessRules')->find($id);

        if ($article === null || ! $article->isLive()) {
            throw new ApiException(404, 'NOT_FOUND', '找不到這篇文章。');
        }

        return $article;
    }

    private function authorizeRead(Article $article, ?User $viewer): void
    {
        match ($this->access->denial($article, $viewer)) {
            null => null,
            'login_required' => throw new ApiException(401, 'ARTICLE_LOGIN_REQUIRED', '請先登入會員才能閱讀這篇文章。'),
            'verification_required' => throw new ApiException(403, 'VERIFICATION_REQUIRED', '請先完成帳號驗證才能閱讀這篇文章。'),
            default => throw new ApiException(403, 'ARTICLE_FORBIDDEN', '這篇文章僅限特定會員閱讀。'),
        };
    }

    /**
     * Live article the authenticated member (route: auth + member.verified) may read.
     */
    private function interactive(Request $request, int $id): Article
    {
        $article = $this->live($id);
        $this->authorizeRead($article, $request->user());

        return $article;
    }

    private function likeState(Article $article, User $member): JsonResponse
    {
        return new JsonResponse(['data' => [
            'liked' => ArticleLike::query()->where('article_id', $article->id)->where('user_id', $member->id)->exists(),
            'like_count' => (int) Article::query()->whereKey($article->id)->value('like_count'),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Article $a): array
    {
        $cover = $a->cover_path !== null ? url('/api/v1/articles/'.$a->id.'/cover') : $a->cover_url;

        return [
            'id' => (string) $a->id,
            'slug' => $a->slug,
            'title' => $a->title,
            'summary' => $a->summary,
            'cover_url' => $cover,
            'author_name' => $a->author_name,
            'categories' => $a->categories->map(fn (ArticleCategory $c) => ['id' => (string) $c->id, 'name' => $c->name])->values()->all(),
            'visibility' => $a->visibility,
            'is_featured' => $a->is_featured,
            'published_at' => $a->published_at?->toIso8601ZuluString(),
            'like_count' => $a->like_count,
            'view_count' => $a->view_count,
        ];
    }

    /**
     * Public nickname only (always the current one); never the real name.
     *
     * @return array<string, mixed>
     */
    private function comment(ArticleComment $c, ?User $viewer): array
    {
        return [
            'id' => (string) $c->id,
            'nickname' => $c->user?->nickname ?? '會員',
            // S9 M03: public badges only (members may hide them, D03).
            'badges' => $c->user_id === null ? [] : MemberVerification::query()->valid()->with('type:id,name')
                ->where('user_id', $c->user_id)->where('is_public', true)->get()->map(fn (MemberVerification $b) => $b->type->name)->unique()->values()->all(),
            'body' => $c->body,
            'created_at' => $c->created_at?->toIso8601ZuluString(),
            'is_mine' => $viewer !== null && $c->user_id === $viewer->id,
            'attachment_count' => (int) ($c->attachment_count ?? $c->attachments()->whereNull('removed_at')->count()),
        ];
    }
}
