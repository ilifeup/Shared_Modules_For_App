<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Services\Content\TagCsv;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListTags extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importCsv')
                ->label('匯入 CSV')
                ->schema([
                    FileUpload::make('file')->label('CSV（欄位：name, color, sort, is_active；重複名稱會略過）')->required()
                        ->disk('local')->directory('tag-imports')->maxSize(1024)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel']),
                ])
                ->action(function (array $data): void {
                    $path = (string) $data['file'];
                    $csv = (string) Storage::disk('local')->get($path);
                    Storage::disk('local')->delete($path);
                    $result = app(TagCsv::class)->import($csv, auth()->user());

                    Notification::make()->persistent()
                        ->title('匯入完成：新增 '.count($result['created']).'、略過 '.count($result['skipped']).'、錯誤 '.count($result['errors']))
                        ->body(implode("\n", array_slice([...$result['skipped'], ...$result['errors']], 0, 30)) ?: null)
                        ->{$result['errors'] === [] ? 'success' : 'warning'}()
                        ->send();
                }),
            Action::make('exportCsv')
                ->label('匯出 CSV')
                ->action(fn (): StreamedResponse => response()->streamDownload(function (): void {
                    echo app(TagCsv::class)->export();
                }, 'tags-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
        ];
    }
}
