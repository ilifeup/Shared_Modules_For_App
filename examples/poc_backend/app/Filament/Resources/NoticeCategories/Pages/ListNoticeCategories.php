<?php

namespace App\Filament\Resources\NoticeCategories\Pages;

use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use Filament\Resources\Pages\ListRecords;

class ListNoticeCategories extends ListRecords
{
    protected static string $resource = NoticeCategoryResource::class;
}
