<?php

namespace App\Support;

use App\Models\Article;
use App\Models\CalendarEvent;
use App\Models\Notice;
use App\Models\User;
use App\Services\ArticleAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * S9 shared content registry for M14 / M15 / M16 / M17 / M19 (technical decision T5).
 * Content is referred to by an allow-listed type and id (never a class name, no
 * cross-module FK); visibility always comes from the existing backend rules:
 * ArticleAccess for articles, audience + status for events and notices.
 */
final class ContentRegistry
{
    public const TYPES = ['article', 'event', 'notice'];

    public function __construct(
        private readonly ArticleAccess $articles,
        private readonly VerificationPolicy $policy,
    ) {}

    public static function valid(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * The content row, or null when it does not exist (or was permanently deleted).
     */
    public function find(string $type, int $id): ?Model
    {
        return match ($type) {
            'article' => Article::query()->with(['accessRules', 'categories'])->find($id),
            'event' => CalendarEvent::query()->with('category')->find($id),
            'notice' => Notice::query()->find($id),
            default => null,
        };
    }

    /**
     * Whether $viewer (null = visitor) may open the content now.
     */
    public function canView(string $type, ?Model $content, ?User $viewer): bool
    {
        if ($content === null) {
            return false;
        }

        $member = $viewer !== null && $viewer->is_active && $this->policy->isSatisfiedBy($viewer);

        return match ($type) {
            'article' => $content instanceof Article && $content->isLive() && $this->articles->canRead($content, $viewer?->is_active ? $viewer : null),
            'event' => $content instanceof CalendarEvent
                && in_array($content->status, [CalendarEvent::STATUS_PUBLISHED, CalendarEvent::STATUS_CANCELLED], true)
                && ($content->audience === CalendarEvent::AUDIENCE_PUBLIC || $member),
            'notice' => $content instanceof Notice
                && Notice::query()->whereKey($content->id)->when(! $member, fn (Builder $q) => $q->visibleToPublic(), fn (Builder $q) => $q->visibleToMembers())->exists(),
            default => false,
        };
    }

    /** Visible to visitors (social previews, public share pages, public tag counts). */
    public function isPublic(string $type, ?Model $content): bool
    {
        return $this->canView($type, $content, null);
    }

    /**
     * Short public-safe card. Only call after canView().
     *
     * @return array{content_type: string, content_id: string, title: string, summary: string|null, image_url: string|null, date: string|null, location: string|null, url: string}
     */
    public function summary(string $type, Model $content): array
    {
        return match ($type) {
            'article' => [
                'content_type' => 'article',
                'content_id' => (string) $content->id,
                'title' => $content->title,
                'summary' => $content->summary,
                'image_url' => $content->cover_path !== null ? url('/api/v1/articles/'.$content->id.'/cover') : $content->cover_url,
                'date' => $content->published_at?->toIso8601ZuluString(),
                'location' => null,
                'url' => $this->webUrl('article', (int) $content->id),
            ],
            'event' => [
                'content_type' => 'event',
                'content_id' => (string) $content->id,
                'title' => $content->title,
                'summary' => $content->description !== null ? Str::limit(strip_tags($content->description), 160) : null,
                'image_url' => null,
                'date' => $content->starts_at->toIso8601ZuluString(),
                'location' => implode(' ', array_filter([$content->location, $content->floor, $content->studio])) ?: null,
                'url' => $this->webUrl('event', (int) $content->id),
            ],
            'notice' => [
                'content_type' => 'notice',
                'content_id' => (string) $content->id,
                'title' => $content->title,
                'summary' => Str::limit(strip_tags((string) $content->body), 160),
                'image_url' => null,
                'date' => $content->published_at?->toIso8601ZuluString(),
                'location' => null,
                'url' => $this->webUrl('notice', (int) $content->id),
            ],
        };
    }

    /**
     * Standard HTTPS URL with the fixed content id (M14-D07, D10).
     *
     * @param  array<string, string>  $utm  optional utm_source / utm_medium / utm_campaign (M17-D20)
     */
    public function webUrl(string $type, int $id, array $utm = []): string
    {
        $path = match ($type) {
            'article' => '/articles/'.$id,
            'event' => '/events/'.$id,
            'notice' => '/notices/'.$id,
        };
        $query = array_filter(array_intersect_key($utm, array_flip(['utm_source', 'utm_medium', 'utm_campaign'])));

        return rtrim((string) config('deeplinks.base_url'), '/').$path.($query !== [] ? '?'.http_build_query($query) : '');
    }

    /** In-App route of the same content. */
    public static function appPath(string $type, int $id): string
    {
        return match ($type) {
            'article' => '/articles/'.$id,
            'event' => '/events/'.$id,
            'notice' => '/notices/'.$id,
        };
    }
}
