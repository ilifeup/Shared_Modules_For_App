<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\CalendarEvents\SeriesForms;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCalendarEvents extends ListRecords
{
    protected static string $resource = CalendarEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('createSeries')
                ->label('建立重複活動')
                ->schema(SeriesForms::createFields())
                ->action(fn (array $data) => SeriesForms::create($data)),
        ];
    }
}
