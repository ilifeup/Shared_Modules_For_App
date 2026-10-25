<?php

namespace App\Filament\Resources\MarketingPlacements;

use App\Filament\Resources\MarketingPlacements\Pages\CreateMarketingPlacement;
use App\Filament\Resources\MarketingPlacements\Pages\EditMarketingPlacement;
use App\Filament\Resources\MarketingPlacements\Pages\ListMarketingPlacements;
use App\Models\MarketingPlacement;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M15 display blocks of this App (D08) and their item limit (D13).
 */
class MarketingPlacementResource extends Resource
{
    protected static ?string $model = MarketingPlacement::class;

    protected static ?string $modelLabel = '展示區塊';

    protected static ?string $pluralModelLabel = '行銷展示區塊';

    protected static ?string $slug = 'marketing-placements';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('marketing.manage') ?? false;
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
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('代碼（App 使用，小寫英數底線）')->required()->maxLength(40)->regex('/^[a-z0-9_]+$/')->unique(ignoreRecord: true)
                ->disabled(fn (?MarketingPlacement $record): bool => $record?->exists ?? false),
            TextInput::make('name')->label('名稱')->required()->maxLength(80),
            TextInput::make('max_items')->label('最多顯示幾則')->numeric()->integer()->minValue(1)->maxValue(20)->default(3)->required(),
            Toggle::make('is_active')->label('啟用')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label('代碼'),
            TextColumn::make('name')->label('名稱'),
            TextColumn::make('max_items')->label('上限'),
            TextColumn::make('is_active')->label('狀態')->formatStateUsing(fn ($state): string => $state ? '啟用' : '【停用】'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingPlacements::route('/'),
            'create' => CreateMarketingPlacement::route('/create'),
            'edit' => EditMarketingPlacement::route('/{record}/edit'),
        ];
    }
}
