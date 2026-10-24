<?php

namespace App\Services\Marketing;

use App\Models\AuditEvent;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlacement;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\ContentRegistry;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * S9 M15 campaigns: server-validated publishing (D25, D26), schedule and state from
 * the server clock (D05, D10), per-placement priority and limits (D09, D13, D27),
 * display only when the viewer may open the target (D17, D28); broken material or
 * targets are hidden and a short error is kept (D29). Links go through M14 URLs and
 * clicks are counted by M17 (D22) — no own statistics.
 */
class MarketingService
{
    public function __construct(
        private readonly ContentRegistry $content,
        private readonly Settings $settings,
    ) {}

    /**
     * Problems that block publishing; empty = publishable.
     *
     * @return list<string>
     */
    public function problems(MarketingCampaign $campaign): array
    {
        $problems = [];
        if (trim((string) $campaign->title) === '') {
            $problems[] = '請填寫標題。';
        }
        if (trim((string) $campaign->button_text) === '') {
            $problems[] = '請填寫按鈕文字。';
        }
        if ($campaign->starts_at === null || $campaign->ends_at === null) {
            $problems[] = '請設定開始與結束時間。';
        } elseif (! $campaign->ends_at->greaterThan($campaign->starts_at)) {
            $problems[] = '結束時間必須晚於開始時間。';
        }
        if (! $campaign->placements()->where('is_active', true)->exists()) {
            $problems[] = '請選擇至少一個啟用中的展示區塊。';
        }
        if ($campaign->image_path !== null && ! Storage::disk('local')->exists($campaign->image_path)) {
            $problems[] = '圖片檔案不存在，請重新上傳。';
        }

        $targetProblem = $this->targetProblem($campaign);
        if ($targetProblem !== null) {
            $problems[] = $targetProblem;
        }

        return $problems;
    }

    public function publish(MarketingCampaign $campaign, User $actor): void
    {
        $problems = $this->problems($campaign);
        if ($problems !== []) {
            throw new ApiException(422, 'CAMPAIGN_INVALID', implode(' ', $problems), ['campaign' => $problems]);
        }
        $campaign->forceFill(['status' => MarketingCampaign::PUBLISHED, 'updated_by' => $actor->id, 'last_error' => null])->save();
        AuditEvent::record('marketing.published', $actor->id, $campaign, ['starts_at' => $campaign->starts_at?->toIso8601ZuluString(), 'ends_at' => $campaign->ends_at?->toIso8601ZuluString()]);
    }

    public function disable(MarketingCampaign $campaign, User $actor): void
    {
        $campaign->forceFill(['status' => MarketingCampaign::DISABLED, 'updated_by' => $actor->id])->save();
        AuditEvent::record('marketing.disabled', $actor->id, $campaign);
    }

    /** New draft, schedule cleared, no statistics copied (D19). */
    public function duplicate(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        $copy = $campaign->replicate(['status', 'starts_at', 'ends_at', 'last_error', 'created_by', 'updated_by']);
        $copy->forceFill([
            'title' => mb_substr($campaign->title.'（複製）', 0, 120),
            'status' => MarketingCampaign::DRAFT,
            'starts_at' => null,
            'ends_at' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();
        $copy->placements()->sync($campaign->placements()->pluck('marketing_placements.id'));
        AuditEvent::record('marketing.duplicated', $actor->id, $copy, ['from' => $campaign->id]);

        return $copy;
    }

    /** M17 aggregates stay until their own retention ends (D21). */
    public function delete(MarketingCampaign $campaign, User $actor): void
    {
        AuditEvent::record('marketing.deleted', $actor->id, null, ['campaign_id' => $campaign->id, 'title' => $campaign->title]);
        $campaign->delete();
    }

    /**
     * Active campaigns of a placement for this viewer, by priority (high first), then
     * newest start, then id (stable), limited to the placement's maximum.
     *
     * @return list<array<string, mixed>>
     */
    public function forPlacement(string $code, ?User $viewer): array
    {
        $placement = MarketingPlacement::query()->where('code', $code)->where('is_active', true)->first();
        if ($placement === null) {
            return [];
        }

        $now = now();
        $campaigns = $placement->campaigns()
            ->where('status', MarketingCampaign::PUBLISHED)
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->orderByDesc('priority')->orderByDesc('starts_at')->orderByDesc('marketing_campaigns.id')
            ->get();

        $items = [];
        foreach ($campaigns as $campaign) {
            if (count($items) >= $placement->max_items) {
                break;
            }
            $item = $this->displayItem($campaign, $viewer);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function displayItem(MarketingCampaign $campaign, ?User $viewer): ?array
    {
        if ($campaign->image_path !== null && ! Storage::disk('local')->exists($campaign->image_path)) {
            $this->fail($campaign, '圖片檔案不存在');

            return null;
        }

        $utm = ['utm_source' => 'app', 'utm_medium' => 'marketing', 'utm_campaign' => 'c'.$campaign->id];
        if ($campaign->target_type === 'url') {
            if ($this->targetProblem($campaign) !== null) {
                $this->fail($campaign, '外部連結無效');

                return null;
            }
            $target = ['type' => 'url', 'id' => null, 'url' => $campaign->target_url, 'app_path' => null];
        } else {
            $content = $this->content->find($campaign->target_type, (int) $campaign->target_id);
            if ($content === null) {
                $this->fail($campaign, '目標內容已不存在');

                return null;
            }
            if (! $this->content->canView($campaign->target_type, $content, $viewer)) {
                return null; // not for this viewer (D17, D28) — not an error
            }
            $target = [
                'type' => $campaign->target_type,
                'id' => (string) $campaign->target_id,
                'url' => $this->content->webUrl($campaign->target_type, (int) $campaign->target_id, $utm),
                'app_path' => ContentRegistry::appPath($campaign->target_type, (int) $campaign->target_id),
            ];
        }

        return [
            'id' => (string) $campaign->id,
            'title' => $campaign->title,
            'summary' => $campaign->summary,
            'image_url' => $campaign->image_path !== null ? url('/api/v1/marketing/campaigns/'.$campaign->id.'/image') : null,
            'button_text' => $campaign->button_text,
            'target' => $target,
            'utm' => $utm,
        ];
    }

    public function isDisplayable(MarketingCampaign $campaign): bool
    {
        return $campaign->state() === 'active';
    }

    private function targetProblem(MarketingCampaign $campaign): ?string
    {
        if (! in_array($campaign->target_type, MarketingCampaign::TARGET_TYPES, true) || ! $this->settings->allows('marketing_target_types', $campaign->target_type)) {
            return '此目標類型未開放推廣。';
        }

        if ($campaign->target_type === 'url') {
            $url = (string) $campaign->target_url;
            $parts = parse_url($url);
            $ok = filter_var($url, FILTER_VALIDATE_URL) !== false && strlen($url) <= 2048
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && ! empty($parts['host'])
                && ! isset($parts['user']) && ! isset($parts['pass']);

            return $ok ? null : '外部連結必須是有效的 https:// 網址。';
        }

        $content = $this->content->find($campaign->target_type, (int) $campaign->target_id);
        if ($content === null) {
            return '找不到目標內容。';
        }

        // Must be openable by at least a signed-in member (never a draft / hidden item).
        $published = match ($campaign->target_type) {
            'article' => $content->isLive(),
            'event' => in_array($content->status, ['published'], true),
            'notice' => $content->status === 'published',
            default => false,
        };

        return $published ? null : '目標內容尚未發布或已下架。';
    }

    private function fail(MarketingCampaign $campaign, string $error): void
    {
        if ($campaign->last_error !== $error) {
            $campaign->forceFill(['last_error' => $error])->saveQuietly();
        }
        Log::info('marketing campaign hidden', ['campaign_id' => $campaign->id, 'reason' => $error]);
    }
}
