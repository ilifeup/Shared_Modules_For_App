<?php

namespace App\Services\Analytics;

use App\Models\AuditEvent;
use App\Models\LinkClick;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * S9 M17 link clicks and confirmed opens.
 *
 * Privacy (D05, D10): no full IP, no long-lived fingerprint — the de-duplication
 * hash is per link and per statistics day (it changes every day). Known bots and
 * abnormal bursts are excluded from the human numbers and counted separately
 * (D09, D13, D28). "Estimated de-duplicated clicks" is an estimate, not a number of
 * people (D03). Recording never breaks the page or App flow (S9-INT-D11).
 */
class LinkAnalytics
{
    /** Same visitor hash more than this many times in ABNORMAL_MINUTES = abnormal burst. */
    public const ABNORMAL_LIMIT = 20;

    public const ABNORMAL_MINUTES = 10;

    public const RAW_DAYS = 90;          // D11

    public const AGGREGATE_MONTHS = 24;  // D11

    public const TYPES = ['article', 'event', 'notice', 'campaign'];

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|facebot|line-poker|linkpreview|preview|embedly|curl|wget|python-requests|httpclient|headless|okhttp|go-http-client|java\//i';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Records a link click (web page hit or App tap). Returns the click id for the
     * later success report, or null when the type is not tracked or recording failed.
     */
    public function click(string $type, int $id, Request $request, string $channel = 'web'): ?string
    {
        try {
            return $this->store($type, $id, $request, $channel, opened: false);
        } catch (Throwable $e) {
            Log::warning('link click not recorded', ['error' => class_basename($e)]);

            return null;
        }
    }

    /**
     * App reports that it opened the content (D21): click + confirmed open at once.
     */
    public function appOpen(string $type, int $id, Request $request): ?string
    {
        try {
            return $this->store($type, $id, $request, 'app', opened: true);
        } catch (Throwable $e) {
            Log::warning('link open not recorded', ['error' => class_basename($e)]);

            return null;
        }
    }

    /**
     * Web page reports a successful open for an earlier click. Repeated reports of
     * the same click count once (D25). Returns whether this report was counted.
     */
    public function confirmOpen(string $clickId): bool
    {
        if (! Str::isUuid($clickId)) {
            return false;
        }

        $click = LinkClick::query()->where('click_id', $clickId)->first();
        if ($click === null || $click->is_bot || $click->is_abnormal) {
            return false;
        }

        $updated = LinkClick::query()->whereKey($click->id)->whereNull('opened_at')->update(['opened_at' => now()]);
        if ($updated === 1) {
            $this->bump($click->stat_date->toDateString(), $click->content_type, (int) $click->content_id, $click->source, $click->device_class, ['opens' => 1]);

            return true;
        }

        return false;
    }

    /**
     * Deletes raw events after 90 days and aggregates after 24 months (D22).
     *
     * @return array{raw_deleted: int, aggregates_deleted: int}
     */
    public function purge(?Carbon $now = null): array
    {
        $now ??= now();
        $raw = LinkClick::query()->where('clicked_at', '<', $now->copy()->subDays(self::RAW_DAYS))->delete();
        $agg = DB::table('link_daily_stats')->where('stat_date', '<', $now->copy()->subMonthsNoOverflow(self::AGGREGATE_MONTHS)->toDateString())->delete();

        $result = ['raw_deleted' => $raw, 'aggregates_deleted' => $agg];
        AuditEvent::record('analytics.cleanup', null, null, $result);

        return $result;
    }

    public function timezone(): string
    {
        return $this->settings->string('analytics_timezone');
    }

    /**
     * @return array{source: string, medium: string|null, campaign: string|null}
     */
    public static function classify(Request $request, string $channel): array
    {
        $utm = static fn (string $key): ?string => is_string($v = $request->input($key)) && preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $v) === 1 ? strtolower($v) : null;
        $source = $utm('utm_source');
        $medium = $utm('utm_medium');
        $campaign = is_string($c = $request->input('utm_campaign')) && preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $c) === 1 ? strtolower($c) : null;

        if ($source !== null) {
            $source = match (true) {
                str_contains($source, 'line') => 'line',
                in_array($source, ['facebook', 'fb', 'instagram', 'ig', 'twitter', 'x', 'threads', 'social'], true) => 'social',
                in_array($source, ['google', 'bing', 'yahoo', 'duckduckgo'], true) => 'search',
                default => 'campaign:'.$source,
            };

            return ['source' => mb_substr($source, 0, 40), 'medium' => $medium, 'campaign' => $campaign];
        }

        $referrer = (string) $request->headers->get('referer', '');
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $source = match (true) {
            $host === '' => $channel === 'web' ? 'direct' : 'unknown',
            str_contains($host, 'line.me') || str_contains($host, 'line.naver') => 'line',
            (bool) preg_match('/(facebook|instagram|twitter|x\.com|t\.co|threads)/', $host) => 'social',
            (bool) preg_match('/(google|bing|yahoo|duckduckgo)\./', $host) => 'search',
            $host === strtolower((string) parse_url((string) config('deeplinks.base_url'), PHP_URL_HOST)) => 'internal',
            default => 'other',
        };

        return ['source' => $source, 'medium' => $medium, 'campaign' => $campaign];
    }

    /** mobile | tablet | desktop | unknown (D17: coarse classes only). */
    public static function deviceClass(string $userAgent): string
    {
        return match (true) {
            $userAgent === '' => 'unknown',
            (bool) preg_match('/ipad|tablet|kindle|silk|(android(?!.*mobile))/i', $userAgent) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android|dart/i', $userAgent) => 'mobile',
            (bool) preg_match('/windows|macintosh|x11|linux|cros/i', $userAgent) => 'desktop',
            default => 'unknown',
        };
    }

    public static function isBot(string $userAgent): bool
    {
        return preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    private function store(string $type, int $id, Request $request, string $channel, bool $opened): ?string
    {
        if (! in_array($type, self::TYPES, true) || ! $this->settings->allows('content_analytics_types', $type)) {
            return null;
        }

        $now = now();
        $statDate = $now->copy()->timezone($this->timezone())->toDateString();
        $ua = mb_substr((string) $request->userAgent(), 0, 500);
        $source = self::classify($request, $channel);
        $device = self::deviceClass($ua);
        $bot = self::isBot($ua);
        // Per link and per day: cannot follow a visitor across links or days.
        $hash = hash('sha256', config('app.key').'|'.$statDate.'|'.$type.':'.$id.'|'.$request->ip().'|'.$ua);

        $recent = LinkClick::query()->where('dedupe_hash', $hash)->where('clicked_at', '>=', $now->copy()->subMinutes(self::ABNORMAL_MINUTES))->count();
        $abnormal = ! $bot && $recent >= self::ABNORMAL_LIMIT;
        $firstToday = ! $bot && ! $abnormal && ! LinkClick::query()->where('dedupe_hash', $hash)->where('is_bot', false)->where('is_abnormal', false)->exists();

        $clickId = (string) Str::uuid();
        LinkClick::query()->create([
            'click_id' => $clickId,
            'content_type' => $type,
            'content_id' => $id,
            'channel' => $channel,
            'source' => $source['source'],
            'medium' => $source['medium'],
            'campaign' => $source['campaign'],
            'device_class' => $device,
            'dedupe_hash' => $hash,
            'is_bot' => $bot,
            'is_abnormal' => $abnormal,
            'stat_date' => $statDate,
            'clicked_at' => $now,
            'opened_at' => $opened && ! $bot && ! $abnormal ? $now : null,
        ]);

        $this->bump($statDate, $type, $id, $source['source'], $device, match (true) {
            $bot => ['bot_excluded' => 1],
            $abnormal => ['abnormal_excluded' => 1],
            default => ['total_clicks' => 1, 'dedup_clicks' => $firstToday ? 1 : 0, 'opens' => $opened ? 1 : 0],
        });

        return $clickId;
    }

    /**
     * @param  array<string, int>  $increments
     */
    private function bump(string $date, string $type, int $id, string $source, string $device, array $increments): void
    {
        $increments = array_filter($increments);
        if ($increments === []) {
            return;
        }

        $now = now();
        $row = ['stat_date' => $date, 'content_type' => $type, 'content_id' => $id, 'source' => $source, 'device_class' => $device, 'created_at' => $now, 'updated_at' => $now];
        $update = ['updated_at' => $now];
        foreach (['total_clicks', 'dedup_clicks', 'opens', 'bot_excluded', 'abnormal_excluded'] as $column) {
            $row[$column] = $increments[$column] ?? 0;
            if (isset($increments[$column])) {
                $update[$column] = DB::raw($column.' + '.(int) $increments[$column]);
            }
        }

        DB::table('link_daily_stats')->upsert([$row], ['stat_date', 'content_type', 'content_id', 'source', 'device_class'], $update);
    }
}
