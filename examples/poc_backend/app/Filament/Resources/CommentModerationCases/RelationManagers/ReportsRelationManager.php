<?php

namespace App\Filament\Resources\CommentModerationCases\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';

    protected static ?string $title = '本輪檢舉';

    public function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();
        $reasons = (array) config('articles.report_reasons', []);

        return $table
            ->columns([
                TextColumn::make('user_id')->label('檢舉會員 ID'),
                TextColumn::make('user.name')->label('姓名'),
                TextColumn::make('reason')->label('理由')->formatStateUsing(fn (string $state): string => (string) ($reasons[$state] ?? $state)),
                TextColumn::make('note')->label('補充說明')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i', $tz),
            ])
            ->defaultSort('created_at');
    }
}
