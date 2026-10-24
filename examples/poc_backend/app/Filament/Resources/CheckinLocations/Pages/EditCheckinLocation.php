<?php

namespace App\Filament\Resources\CheckinLocations\Pages;

use App\Filament\Resources\CheckinLocations\CheckinLocationResource;
use Filament\Resources\Pages\EditRecord;

class EditCheckinLocation extends EditRecord
{
    protected static string $resource = CheckinLocationResource::class;
}
