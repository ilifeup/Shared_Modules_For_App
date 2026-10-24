<?php

namespace App\Filament\Resources\ArticleCategories\Pages;

use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditArticleCategory extends EditRecord
{
    protected static string $resource = ArticleCategoryResource::class;
}
