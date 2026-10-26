<?php

namespace App\Filament\Resources\EventCategories;

use App\Filament\Resources\EventCategories\Pages\CreateEventCategory;
use App\Filament\Resources\EventCategories\Pages\EditEventCategory;
use App\Filament\Resources\EventCategories\Pages\ListEventCategories;
use App\Models\EventCategory;
use App\Models\VerificationType;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M09/M08 活動類別: calendar colour and booking rules per category.
 */
class EventCategoryResource extends Resource
{
    protected static ?string $model = EventCategory::class;

    protected static ?string $modelLabel = '活動類別';

    protected static ?string $pluralModelLabel = '活動類別';

    protected static ?string $slug = 'event-categories';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('events.manage') ?? false;
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
        return false; // events keep their category history
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('名稱')->required()->maxLength(50)->unique(ignoreRecord: true),
            ColorPicker::make('color')->label('日曆顏色')->regex('/^#[0-9A-Fa-f]{6}$/')->default('#3366CC')->required(),
            TextInput::make('sort')->label('排序')->numeric()->minValue(0)->default(0),
            Toggle::make('allow_proxy_booking')->label('開放代報名（一次多位參加者）'),
            Select::make('required_verification_type_id')->label('報名需具備的認證（選填，報名當下檢查）')
                ->options(fn (): array => VerificationType::query()->orderBy('sort')->pluck('name', 'id')->all())->nullable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('名稱')->searchable(),
            TextColumn::make('color')->label('顏色')->formatStateUsing(fn (string $state): string => '■ '.$state),
            TextColumn::make('allow_proxy_booking')->label('代報名')->formatStateUsing(fn ($state): string => $state ? '可' : '不可'),
            TextColumn::make('requiredVerificationType.name')->label('需認證')->placeholder('—'),
            TextColumn::make('events_count')->label('活動數')->counts('events'),
        ])->defaultSort('sort')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventCategories::route('/'),
            'create' => CreateEventCategory::route('/create'),
            'edit' => EditEventCategory::route('/{record}/edit'),
        ];
    }
}
