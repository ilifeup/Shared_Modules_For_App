<?php

namespace App\Filament\Resources\Articles\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S8 M05-B03 admin view of who liked an article: member ID and the real name kept
 * in the profile, regardless of the member's public like-list setting.
 */
class LikesRelationManager extends RelationManager
{
    protected static string $relationship = 'likes';

    protected static ?string $title = '按讚名單（管理員）';

    /** Real names follow the existing member-data permission (M02). */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('members.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('user_id')->label('會員 ID'),
                TextColumn::make('user.name')->label('真實姓名')->searchable(),
                TextColumn::make('user.nickname')->label('公開暱稱')->placeholder('—'),
                TextColumn::make('user.show_in_like_list')->label('公開於名單')
                    ->formatStateUsing(fn ($state): string => $state ? '公開' : '不公開'),
                TextColumn::make('created_at')->label('按讚時間')->dateTime('Y-m-d H:i', $tz),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
