<?php

namespace App\Filament\Resources\NoticeCategories\Pages;

use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNoticeCategory extends CreateRecord
{
    protected static string $resource = NoticeCategoryResource::class;
}
