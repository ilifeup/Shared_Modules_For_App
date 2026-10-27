<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\CalendarEvent;
use App\Models\Notice;
use App\Services\Analytics\LinkAnalytics;
use App\Support\ContentRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * S9 M14 HTTPS web fallback for shared links (/articles/{id}, /events/{id},
 * /notices/{id}): the same URL opens the App when installed (App Links /
 * Universal Links), otherwise this page. Only public content shows content and
 * Open Graph metadata; member content shows a login prompt without its title (D09).
 * Every hit is an M17 web click; the page itself reports the successful open.
 * Shared event pages are informational; booking requires a member account.
 */
class ContentPageController extends Controller
{
    public function __construct(
        private readonly ContentRegistry $content,
        private readonly LinkAnalytics $analytics,
    ) {}

    /** /articles/{idOrSlug} — S8 slug links keep working; new links use the fixed id. */
    public function article(Request $request, string $key): View|Response
    {
        $article = ctype_digit($key)
            ? Article::query()->with('accessRules')->find((int) $key)
            : Article::query()->with('accessRules')->where('slug', $key)->first();
        if ($article === null || ! $article->isLive()) {
            return $this->unavailable();
        }

        $public = $this->content->isPublic('article', $article);

        return view('links.article', [
            'article' => $public ? $article : null,
            'pageTitle' => $public ? $article->title : '會員文章',
            'og' => $public ? $this->og('article', $article) : null,
            'clickId' => $this->analytics->click('article', $article->id, $request),
        ]);
    }

    public function notice(Request $request, int $id): View|Response
    {
        $notice = Notice::query()->find($id);
        if ($notice === null || ! Notice::query()->whereKey($id)->visible()->exists()) {
            return $this->unavailable();
        }
        $public = $this->content->isPublic('notice', $notice);

        return view('links.notice', [
            'notice' => $public ? $notice : null,
            'pageTitle' => $public ? $notice->title : '會員公告',
            'og' => $public ? $this->og('notice', $notice) : null,
            'clickId' => $this->analytics->click('notice', $notice->id, $request),
        ]);
    }

    public function event(Request $request, int $id): View|Response
    {
        $event = CalendarEvent::query()->with('category')->listed()->find($id);
        if ($event === null) {
            return $this->unavailable();
        }
        $public = $this->content->isPublic('event', $event);

        return view('links.event', [
            'event' => $public ? $event : null,
            'pageTitle' => $public ? $event->title : '會員活動',
            'og' => $public ? $this->og('event', $event) : null,
            'clickId' => $this->analytics->click('event', $event->id, $request),
        ]);
    }

    /**
     * @return array{title: string, description: string, url: string, image: string|null}
     */
    private function og(string $type, mixed $model): array
    {
        $summary = $this->content->summary($type, $model);

        return [
            'title' => $summary['title'],
            'description' => Str::limit((string) ($summary['summary'] ?? ''), 150),
            'url' => $summary['url'],
            // Existing cover first, otherwise the App's default image (M14-D28).
            'image' => $type === 'article' && $model->cover_url !== null ? $model->cover_url : config('deeplinks.default_image'),
        ];
    }

    private function unavailable(?string $message = null): Response
    {
        return response()->view('links.unavailable', ['pageTitle' => '連結無法開啟', 'message' => $message], 404);
    }
}
