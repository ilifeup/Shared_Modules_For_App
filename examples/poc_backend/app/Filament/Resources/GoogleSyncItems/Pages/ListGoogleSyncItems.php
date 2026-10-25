<?php

namespace App\Filament\Resources\GoogleSyncItems\Pages;

use App\Filament\Resources\GoogleSyncItems\GoogleSyncItemResource;
use Filament\Resources\Pages\ListRecords;

class ListGoogleSyncItems extends ListRecords
{
    protected static string $resource = GoogleSyncItemResource::class;
}
