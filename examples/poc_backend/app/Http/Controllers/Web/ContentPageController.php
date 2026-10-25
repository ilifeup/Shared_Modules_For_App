<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\CalendarEvent;
use App\Models\Notice;
use App\Services\Analytics\LinkAnalytics;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Booking\GuestClaimService;
use App\Support\Api\ApiException;
use App\Support\ContentRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * S9 M14 HTTPS web fallback for shared links (/articles/{id}, /events/{id},
 * /notices/{id}): the same URL opens the App when installed (App Links /
 * Universal Links), otherwise this page. Only public content shows content and
 * Open Graph metadata; member content shows a login prompt without its title (D09).
 * Every hit is an M17 web click; the page itself reports the successful open.
 * Events open to guests carry the M08 guest booking form (D06).
 */
class ContentPageController extends Controller
{
    public function __construct(
        private readonly ContentRegistry $content,
        private readonly LinkAnalytics $analytics,
        private readonly BookingEngine $engine,
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

    /**
     * @param  array{status: string, seats: int}|null  $result
     */
    public function event(Request $request, int $id, ?array $result = null): View|Response
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
            'clickId' => $result === null ? $this->analytics->click('event', $event->id, $request) : null,
            'guestBooking' => $public && $this->guestBookable($event),
            'maxSeats' => max(1, min(10, (int) $event->booking_max_seats)),
            'clientRequestId' => (string) Str::uuid(),
            'result' => $result,
        ]);
    }

    /**
     * POST /events/{id}/guest-booking — M08 guest booking (name + email or phone, D06).
     */
    public function guestBooking(Request $request, int $id): View|Response|RedirectResponse
    {
        $event = CalendarEvent::query()->with('category')->listed()->find($id);
        if ($event === null || ! $this->content->isPublic('event', $event) || ! $this->guestBookable($event)) {
            return $this->unavailable('此活動不開放訪客報名。');
        }

        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:255', 'required_without:contact_phone'],
            'contact_phone' => ['nullable', 'string', 'max:20', 'required_without:contact_email'],
            'attendees' => ['required', 'array', 'max:50'],
            'attendees.*' => ['nullable', 'string', 'max:80'],
            'client_request_id' => ['required', 'uuid'],
        ], [
            'contact_email.required_without' => '請填寫 Email 或手機。',
            'contact_phone.required_without' => '請填寫 Email 或手機。',
        ]);

        $phone = GuestClaimService::normalizePhone($data['contact_phone'] ?? null);
        if (($data['contact_phone'] ?? null) !== null && $phone === null) {
            return back()->withErrors(['contact_phone' => '手機格式錯誤（例如 0912345678）。'])->withInput();
        }
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $data['attendees']), fn (string $n): bool => $n !== ''));

        try {
            $group = $this->engine->book($event, new BookingRequest(
                booker: null,
                contactName: trim($data['contact_name']),
                contactEmail: GuestClaimService::normalizeEmail($data['contact_email'] ?? null),
                contactPhone: $phone,
                attendees: array_map(fn (string $n) => ['name' => $n, 'member_user_id' => null], $names),
                clientRequestId: $data['client_request_id'],
            ));
        } catch (ApiException $e) {
            return back()->withErrors(['booking' => $e->getMessage()])->withInput();
        }

        return $this->event($request, $id, ['status' => $group->status, 'seats' => $group->seat_count]);
    }

    private function guestBookable(CalendarEvent $event): bool
    {
        return $event->status === CalendarEvent::STATUS_PUBLISHED && $event->booking_enabled
            && ($event->category?->allow_guest_booking ?? false) && $event->category?->required_verification_type_id === null
            && now()->lt($event->bookingClosesAt());
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
