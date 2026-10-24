<?php

namespace App\Services\Analytics;

use App\Models\MarketingCampaign;
use App\Services\Content\TagCsv;
use App\Support\ContentRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S9 M17 report over daily aggregates only (never raw events): summary, trend,
 * ranking and detail with the App's fixed statistics time zone (D07, D15–D19, D29).
 */
class LinkReport
{
    public const PERIODS = ['today' => '今日', 'yesterday' => '昨天', '7d' => '近 7 天', '30d' => '近 30 天', 'custom' => '自訂'];

    public function __construct(
        private readonly LinkAnalytics $analytics,
        private readonly ContentRegistry $content,
    ) {}

    /**
     * @return array{0: string, 1: string} inclusive Y-m-d range in the statistics zone
     */
    public function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = now()->timezone($this->analytics->timezone())->startOfDay();

        return match ($period) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            '30d' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
            'custom' => [Carbon::parse($from ?? $today)->toDateString(), Carbon::parse($to ?? $today)->toDateString()],
            default => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @param  array{type?: string|null, content?: int|null, source?: string|null}  $filters
     */
    public function query(string $from, string $to, array $filters): Builder
    {
        return DB::table('link_daily_stats')->whereBetween('stat_date', [$from, $to])
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where('content_type', $filters['type']))
            ->when(! empty($filters['content']), fn (Builder $q) => $q->where('content_id', (int) $filters['content']))
            ->when(! empty($filters['source']), fn (Builder $q) => $q->where('source', $filters['source']));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(string $from, string $to, array $filters, string $metric = 'total'): array
    {
        $sum = 'SUM(total_clicks) as total, SUM(dedup_clicks) as dedup, SUM(opens) as opens, SUM(bot_excluded) as bots, SUM(abnormal_excluded) as abnormal';
        $summary = (array) $this->query($from, $to, $filters)->selectRaw($sum)->first();
        $trend = $this->query($from, $to, $filters)->selectRaw('stat_date, '.$sum)->groupBy('stat_date')->orderBy('stat_date')->get();
        $order = $metric === 'dedup' ? 'dedup' : 'total';
        $top = $this->query($from, $to, $filters)->selectRaw('content_type, content_id, '.$sum)
            ->groupBy('content_type', 'content_id')->orderByDesc($order)->orderBy('content_type')->orderBy('content_id')->limit(20)->get();
        $sources = $this->query($from, $to, $filters)->selectRaw('source, '.$sum)->groupBy('source')->orderByDesc('total')->get();
        $devices = $this->query($from, $to, $filters)->selectRaw('device_class, '.$sum)->groupBy('device_class')->orderByDesc('total')->get();

        return [
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'dedup' => (int) ($summary['dedup'] ?? 0),
                'opens' => (int) ($summary['opens'] ?? 0),
                'unconfirmed' => max(0, (int) ($summary['total'] ?? 0) - (int) ($summary['opens'] ?? 0)),
                'bots' => (int) ($summary['bots'] ?? 0),
                'abnormal' => (int) ($summary['abnormal'] ?? 0),
            ],
            'trend' => $trend,
            'top' => $top->map(fn ($row) => (array) $row + ['label' => $this->label($row->content_type, (int) $row->content_id)])->all(),
            'sources' => $sources,
            'devices' => $devices,
            'as_of' => $this->query($from, $to, $filters)->max('updated_at'),
            'timezone' => $this->analytics->timezone(),
        ];
    }

    /**
     * CSV of the aggregates for the current filters only (D19) — no raw events.
     *
     * @param  array<string, mixed>  $filters
     */
    public function csv(string $from, string $to, array $filters): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['stat_date', 'content_type', 'content_id', 'source', 'device_class', 'total_clicks', 'estimated_dedup_clicks', 'confirmed_opens', 'bot_excluded', 'abnormal_excluded'], escape: '\\');
        $this->query($from, $to, $filters)->orderBy('stat_date')->orderBy('content_type')->orderBy('content_id')->orderBy('id')
            ->each(function ($row) use ($out): void {
                fputcsv($out, [
                    $row->stat_date, $row->content_type, $row->content_id, TagCsv::safe((string) $row->source), $row->device_class,
                    $row->total_clicks, $row->dedup_clicks, $row->opens, $row->bot_excluded, $row->abnormal_excluded,
                ], escape: '\\');
            });
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Title for admins; deleted content shows no private information (D26). */
    public function label(string $type, int $id): string
    {
        if ($type === 'campaign') {
            $c = MarketingCampaign::query()->find($id);

            return $c ? '行銷 #'.$id.' '.$c->title : '行銷 #'.$id.'（已刪除）';
        }
        $model = ContentRegistry::valid($type) ? $this->content->find($type, $id) : null;

        return $model ? $type.' #'.$id.' '.($model->title ?? '') : $type.' #'.$id.'（已刪除）';
    }
}
