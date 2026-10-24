<?php

namespace App\Filament\Resources\CheckinLocations\Pages;

use App\Filament\Resources\CheckinLocations\CheckinLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCheckinLocation extends CreateRecord
{
    protected static string $resource = CheckinLocationResource::class;
}
