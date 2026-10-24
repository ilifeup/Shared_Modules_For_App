<?php

namespace App\Filament\Resources\CommentModerationCases\Pages;

use App\Filament\Resources\CommentModerationCases\CommentModerationCaseResource;
use App\Models\CommentModerationCase;
use App\Services\CommentModerationService;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCommentModerationCase extends EditRecord
{
    protected static string $resource = CommentModerationCaseResource::class;

    protected static ?string $title = '處理檢舉案件';

    protected function getHeaderActions(): array
    {
        return [
            $this->resolveAction('restore', '恢復公開', 'success'),
            $this->resolveAction('keep_hidden', '維持隱藏', 'warning'),
            $this->resolveAction('delete', '刪除留言', 'danger'),
            $this->resolveAction('close', '結案（不變更留言）', 'gray'),
        ];
    }

    /** The case is read-only here; changes go through the actions above. */
    protected function getFormActions(): array
    {
        return [];
    }

    private function resolveAction(string $action, string $label, string $color): Action
    {
        return Action::make($action)
            ->label($label)
            ->color($color)
            ->visible(fn (): bool => $this->caseRecord()->isOpen())
            ->requiresConfirmation()
            ->action(function () use ($action, $label): void {
                try {
                    app(CommentModerationService::class)->resolve($this->caseRecord(), $action, auth()->user());
                    Notification::make()->title('已'.$label.'，案件已結案')->success()->send();
                } catch (ApiException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
                $this->record->refresh();
                $this->fillForm();
            });
    }

    private function caseRecord(): CommentModerationCase
    {
        /** @var CommentModerationCase $case */
        $case = $this->record;

        return $case;
    }
}
