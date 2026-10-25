<?php

namespace App\Filament\Resources\ArticleComments\Pages;

use App\Filament\Resources\ArticleComments\ArticleCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListArticleComments extends ListRecords
{
    protected static string $resource = ArticleCommentResource::class;
}
