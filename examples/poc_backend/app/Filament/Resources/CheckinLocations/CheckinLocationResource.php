<?php

namespace App\Filament\Resources\CheckinLocations;

use App\Filament\Resources\CheckinLocations\Pages\CreateCheckinLocation;
use App\Filament\Resources\CheckinLocations\Pages\EditCheckinLocation;
use App\Filament\Resources\CheckinLocations\Pages\ListCheckinLocations;
use App\Models\CalendarEvent;
use App\Models\CheckinLocation;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M11 fixed Location QR codes (D09, D19, D22). The printed QR encodes the HTTPS
 * link /checkin/l/{token}; one location may serve many occurrences.
 */
class CheckinLocationResource extends Resource
{
    protected static ?string $model = CheckinLocation::class;

    protected static ?string $modelLabel = '簽到地點';

    protected static ?string $pluralModelLabel = '簽到地點 QR';

    protected static ?string $slug = 'checkin-locations';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('checkin.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // deactivate instead (printed codes stop working)
    }

    public static function url(CheckinLocation $location): string
    {
        return rtrim((string) config('deeplinks.base_url'), '/').'/checkin/l/'.$location->token;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('地點名稱')->required()->maxLength(255),
            TextInput::make('latitude')->label('緯度（GPS 驗證用，選填）')->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->label('經度（選填）')->numeric()->minValue(-180)->maxValue(180),
            Toggle::make('is_active')->label('啟用')->default(true),
            Select::make('events')->label('適用的活動場次')->relationship('events', 'title')->multiple()->searchable()
                ->getOptionLabelFromRecordUsing(fn (CalendarEvent $record): string => '#'.$record->id.' '.$record->title.'（'.$record->starts_at->timezone($record->timezone ?? 'Asia/Taipei')->format('Y-m-d H:i').'）'),
            TextEntry::make('qr_url')->label('QR Code 內容（張貼的網址）')
                ->state(fn (?CheckinLocation $record): string => $record?->exists ? static::url($record) : '儲存後產生')
                ->helperText('可用 App 工作人員畫面顯示或任何 QR 產生器列印此網址；停用即失效。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('地點')->searchable(),
            TextColumn::make('is_active')->label('狀態')->formatStateUsing(fn ($state): string => $state ? '啟用' : '【停用】'),
            TextColumn::make('events_count')->label('場次數')->counts('events'),
            TextColumn::make('url')->label('QR 網址')->state(fn (CheckinLocation $record): string => static::url($record))->copyable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCheckinLocations::route('/'),
            'create' => CreateCheckinLocation::route('/create'),
            'edit' => EditCheckinLocation::route('/{record}/edit'),
        ];
    }
}
