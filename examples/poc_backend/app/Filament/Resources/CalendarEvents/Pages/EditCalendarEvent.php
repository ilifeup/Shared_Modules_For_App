<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use Filament\Resources\Pages\EditRecord;

class EditCalendarEvent extends EditRecord
{
    protected static string $resource = CalendarEventResource::class;
}
