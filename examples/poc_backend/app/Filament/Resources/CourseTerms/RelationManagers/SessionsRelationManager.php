<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';
    protected static ?string $title = '課堂列表';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('課堂 ID'),
                TextColumn::make('event.title')->label('課堂名稱')->searchable(),
                TextColumn::make('event.starts_at')->label('開始時間')->dateTime('Y-m-d H:i', timezone: \App\Filament\Resources\CalendarEvents\CalendarEventResource::displayTimezone())->sortable(),
                TextColumn::make('event.ends_at')->label('結束時間')->dateTime('Y-m-d H:i'),
                TextColumn::make('makeup_capacity')->label('補課名額'),
        ])->recordActions([
            Action::make('editEvent')->label('編輯課堂活動')
                ->url(fn ($record): string => \App\Filament\Resources\CalendarEvents\CalendarEventResource::getUrl('edit', ['record' => $record->calendar_event_id])),
        ]);
    }
}
