<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Models\AuditEvent;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketingCampaign extends CreateRecord
{
    protected static string $resource = MarketingCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => 'draft', 'created_by' => auth()->id(), 'updated_by' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        AuditEvent::record('marketing.created', auth()->id(), $this->record);
    }
}
