<?php

namespace App\Filament\Resources\CheckinLocations\Pages;

use App\Filament\Resources\CheckinLocations\CheckinLocationResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckinLocations extends ListRecords
{
    protected static string $resource = CheckinLocationResource::class;
}
