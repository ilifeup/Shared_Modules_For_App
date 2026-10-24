<?php

namespace App\Filament\Resources\ArticleCategories\Pages;

use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListArticleCategories extends ListRecords
{
    protected static string $resource = ArticleCategoryResource::class;
}
