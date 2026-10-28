<?php

namespace App\Filament\Resources\CourseTerms\Pages;

use App\Filament\Resources\CourseTerms\CourseTermResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseTerms extends ListRecords
{
    protected static string $resource = CourseTermResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
