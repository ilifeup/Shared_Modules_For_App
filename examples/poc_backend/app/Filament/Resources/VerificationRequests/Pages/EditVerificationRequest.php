<?php

namespace App\Filament\Resources\VerificationRequests\Pages;

use App\Filament\Resources\VerificationRequests\VerificationRequestResource;
use App\Models\VerificationRequest;
use App\Services\Verification\VerificationService;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVerificationRequest extends EditRecord
{
    protected static string $resource = VerificationRequestResource::class;

    protected static ?string $title = '審核認證申請';

    protected function getHeaderActions(): array
    {
        return [$this->vote('approve', '同意', 'success'), $this->vote('reject', '拒絕', 'danger')];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    private function vote(string $decision, string $label, string $color): Action
    {
        return Action::make($decision)
            ->label($label)
            ->color($color)
            ->visible(fn (): bool => $this->record->status === VerificationRequest::PENDING)
            ->schema([TextInput::make('comment')->label($decision === 'reject' ? '拒絕原因（會通知會員）' : '備註（選填）')->maxLength(255)->required($decision === 'reject')])
            ->action(function (array $data) use ($decision, $label): void {
                try {
                    /** @var VerificationRequest $request */
                    $request = $this->record;
                    $result = app(VerificationService::class)->review($request, auth()->user(), $decision, $data['comment'] ?? null);
                    Notification::make()->title('已'.$label.'：'.VerificationRequestResource::STATUS_LABELS[$result->status])->success()->send();
                } catch (ApiException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
                $this->record->refresh();
                $this->fillForm();
            });
    }
}
