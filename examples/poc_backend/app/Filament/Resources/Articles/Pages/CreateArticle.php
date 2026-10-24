<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The real creator is kept; author_name is only the displayed name.
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        ArticleResource::saveAccessRules($this->record, $this->form->getRawState());
    }
}
