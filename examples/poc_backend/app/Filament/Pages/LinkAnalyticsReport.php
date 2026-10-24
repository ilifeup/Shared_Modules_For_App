<?php

namespace App\Filament\Pages;

use App\Services\Analytics\LinkReport;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * S9 M17 連結成效 — only for the separately granted analytics.view (D06, D23):
 * summary, trend, ranking (total or estimated de-duplicated), sources, devices,
 * excluded traffic shown separately (D28), CSV of aggregates (D19).
 */
class LinkAnalyticsReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '分析';

    protected static ?string $navigationLabel = '連結點擊成效';

    protected static ?string $title = '連結點擊成效（M17）';

    protected static ?string $slug = 'link-analytics';

    protected string $view = 'filament.pages.link-analytics';

    #[Url]
    public string $period = '7d';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url]
    public ?string $type = null;

    #[Url]
    public ?string $content = null;

    #[Url]
    public ?string $source = null;

    #[Url]
    public string $metric = 'total';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('analytics.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        abort_unless(static::canAccess(), 403);
        $service = app(LinkReport::class);
        [$from, $to] = $service->range(array_key_exists($this->period, LinkReport::PERIODS) ? $this->period : '7d', $this->from, $this->to);

        return ['from' => $from, 'to' => $to] + $service->build($from, $to, $this->filters(), $this->metric === 'dedup' ? 'dedup' : 'total');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')->label('匯出目前條件 CSV（彙總）')
                ->action(function (): StreamedResponse {
                    abort_unless(static::canAccess(), 403);
                    $service = app(LinkReport::class);
                    [$from, $to] = $service->range($this->period, $this->from, $this->to);

                    $csv = $service->csv($from, $to, $this->filters());

                    return response()->streamDownload(function () use ($csv): void {
                        echo $csv;
                    }, 'link-stats-'.$from.'_'.$to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    /**
     * @return array{type: string|null, content: int|null, source: string|null}
     */
    private function filters(): array
    {
        return [
            'type' => in_array($this->type, ['article', 'event', 'notice', 'campaign'], true) ? $this->type : null,
            'content' => ctype_digit((string) $this->content) ? (int) $this->content : null,
            'source' => $this->source !== null && preg_match('/^[a-z0-9_.:\-]{1,40}$/', $this->source) === 1 ? $this->source : null,
        ];
    }
}
