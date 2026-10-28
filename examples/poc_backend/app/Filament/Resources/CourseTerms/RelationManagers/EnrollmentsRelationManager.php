<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';
    protected static ?string $title = '報名與候補名單';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('報名 ID'),
                TextColumn::make('user_id')->label('會員 ID'),
                TextColumn::make('status')->label('狀態'),
                TextColumn::make('waitlist_sequence')->label('候補順位')->placeholder('—'),
                TextColumn::make('fee_confirmed_at')->label('收款確認時間')->dateTime('Y-m-d H:i')->placeholder('—'),
        ]);
    }
}
