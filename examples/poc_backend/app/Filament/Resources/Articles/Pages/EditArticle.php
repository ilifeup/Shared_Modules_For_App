<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\ArticleAccessRule;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Article $article */
        $article = $this->record;
        $data['access_levels'] = $article->accessRules->where('type', ArticleAccessRule::TYPE_LEVEL)->pluck('value')->values()->all();
        $data['access_roles'] = $article->accessRules->where('type', ArticleAccessRule::TYPE_ROLE)->pluck('value')->values()->all();

        return $data;
    }

    protected function afterSave(): void
    {
        ArticleResource::saveAccessRules($this->record, $this->form->getRawState());
    }
}
