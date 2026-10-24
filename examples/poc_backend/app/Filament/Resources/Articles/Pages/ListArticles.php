<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Services\ArticleImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importMarkdown')
                ->label('匯入 Markdown')
                ->schema([
                    FileUpload::make('file')->label('.md 檔案（可含 YAML Front Matter）')->required()
                        ->disk('local')->directory('article-imports')->maxSize(2048)
                        ->acceptedFileTypes(['text/markdown', 'text/x-markdown', 'text/plain'])
                        ->storeFileNamesIn('original_name'),
                    Checkbox::make('update_existing')->label('若已有同名文章，以檔案更新其標題與內文（其他設定不變）'),
                ])
                ->action(function (array $data): void {
                    $path = (string) $data['file'];
                    $markdown = (string) Storage::disk('local')->get($path);
                    Storage::disk('local')->delete($path);

                    $result = app(ArticleImporter::class)->import(
                        (string) ($data['original_name'] ?? basename($path)),
                        $markdown,
                        (bool) ($data['update_existing'] ?? false),
                        auth()->user(),
                    );

                    match ($result['status']) {
                        'duplicate' => Notification::make()->warning()->persistent()
                            ->title('已有同名文章，未匯入')
                            ->body('「'.$result['existing']->title.'」（#'.$result['existing']->id.'）已存在。如要以檔案更新標題與內文，請勾選「更新同名文章」後重新匯入。')
                            ->send(),
                        'updated' => Notification::make()->success()->title('已更新同名文章的標題與內文')->send(),
                        default => Notification::make()->success()->title('已匯入為草稿')
                            ->body($result['unknown_categories'] === [] ? null : '找不到的分類未套用：'.implode('、', $result['unknown_categories']))
                            ->send(),
                    };
                }),
        ];
    }
}
