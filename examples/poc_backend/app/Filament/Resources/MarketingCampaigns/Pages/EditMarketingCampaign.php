<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Models\AuditEvent;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Edits apply on the next load (D18). A published campaign that no longer passes
 * validation is switched back to draft so broken material is never displayed.
 */
class EditMarketingCampaign extends EditRecord
{
    protected static string $resource = MarketingCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data + ['updated_by' => auth()->id()];
    }

    protected function afterSave(): void
    {
        /** @var MarketingCampaign $campaign */
        $campaign = $this->record->refresh();
        AuditEvent::record('marketing.updated', auth()->id(), $campaign);

        if ($campaign->status === MarketingCampaign::PUBLISHED && ($problems = app(MarketingService::class)->problems($campaign)) !== []) {
            $campaign->forceFill(['status' => MarketingCampaign::DRAFT])->save();
            Notification::make()->title('修改後未通過發布驗證，已改為草稿')->body(implode("\n", $problems))->warning()->persistent()->send();
        }
    }
}
