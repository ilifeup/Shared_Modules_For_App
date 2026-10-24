<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Models\MemberLevelAssignment;
use App\Services\MembershipService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Level history of one member. Rows are never deleted; an active period can be revoked.
 */
class LevelAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'levelAssignments';

    protected static ?string $title = '會員等級紀錄';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('level.name')->label('等級'),
                TextColumn::make('state')->label('狀態')
                    ->state(fn (MemberLevelAssignment $record): string => $record->stateLabel()),
                TextColumn::make('starts_at')->label('開始')->dateTime('Y-m-d H:i'),
                TextColumn::make('ends_at')->label('結束')->dateTime('Y-m-d H:i')->placeholder('不限期'),
                TextColumn::make('reason')->label('原因')->placeholder('—'),
                TextColumn::make('assigner.name')->label('指派者')->placeholder('—'),
                TextColumn::make('created_at')->label('建立時間')->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->label('撤銷')
                    ->visible(fn (MemberLevelAssignment $record): bool => $record->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('撤銷後會員立即回到一般等級（若無其他有效等級），紀錄會保留。')
                    ->action(function (MemberLevelAssignment $record): void {
                        app(MembershipService::class)->revoke($record, auth()->user());

                        Notification::make()->title('已撤銷此等級期間')->success()->send();
                    }),
            ]);
    }
}
