<?php

namespace App\Filament\Resources\GoogleSyncItems;

use App\Filament\Resources\GoogleSyncItems\Pages\ListGoogleSyncItems;
use App\Models\GoogleCalendarEventMapping;
use App\Services\Calendar\GoogleCalendarSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S9 M09 Google sync status (S9-L03 A): failed items after the automatic retries,
 * with a manual retry. Never shows tokens or private entry contents.
 */
class GoogleSyncItemResource extends Resource
{
    protected static ?string $model = GoogleCalendarEventMapping::class;

    protected static ?string $modelLabel = 'Google 同步項目';

    protected static ?string $pluralModelLabel = 'Google 日曆同步';

    protected static ?string $slug = 'google-sync';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('events.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('connection.user'))
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('connection.user.name')->label('會員'),
                TextColumn::make('source_type')->label('類型')->formatStateUsing(fn (string $state): string => $state === 'booking' ? '我的報名（活動 #）' : '私人行程（內容不顯示）'),
                TextColumn::make('source_id')->label('來源 ID'),
                TextColumn::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => ['failed' => '【失敗】', 'synced' => '已同步', 'removed' => '已移除', 'pending' => '等待中'][$state] ?? $state),
                TextColumn::make('attempts')->label('嘗試次數'),
                TextColumn::make('last_error')->label('錯誤')->placeholder('—')->wrap(),
                TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i', (string) config('app.display_timezone', 'UTC')),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([SelectFilter::make('status')->label('狀態')->options(['failed' => '失敗', 'pending' => '等待中', 'synced' => '已同步', 'removed' => '已移除'])->default('failed')])
            ->recordActions([
                Action::make('retry')->label('立即重試')
                    ->visible(fn (GoogleCalendarEventMapping $r): bool => $r->status === 'failed')
                    ->action(function (GoogleCalendarEventMapping $record): void {
                        app(GoogleCalendarSync::class)->retry($record, auth()->user());
                        Notification::make()->title('已重新排入同步')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListGoogleSyncItems::route('/')];
    }
}
