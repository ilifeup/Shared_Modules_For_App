<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

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
                TextColumn::make('calendar_event_id')->label('活動 ID'),
                TextColumn::make('makeup_capacity')->label('補課名額'),
        ]);
    }
}
