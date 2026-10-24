<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        // The real creator is kept (not mass-assignable); author_name is only the displayed name.
        $article = new Article($data);
        $article->created_by = auth()->id();
        $article->save();

        return $article;
    }

    protected function afterCreate(): void
    {
        ArticleResource::saveAccessRules($this->record, $this->form->getRawState());
        ArticleResource::saveTags($this->record, $this->form->getRawState());
    }
}
