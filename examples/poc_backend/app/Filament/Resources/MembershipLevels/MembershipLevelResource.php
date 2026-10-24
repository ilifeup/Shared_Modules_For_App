<?php

namespace App\Filament\Resources\MembershipLevels;

use App\Filament\Resources\MembershipLevels\Pages\EditMembershipLevel;
use App\Filament\Resources\MembershipLevels\Pages\ListMembershipLevels;
use App\Models\MembershipLevel;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * M02 levels (一般 / VIP): name and benefits are editable; codes are fixed and
 * levels cannot be created or deleted here. Levels grant no admin rights.
 */
class MembershipLevelResource extends Resource
{
    protected static ?string $model = MembershipLevel::class;

    protected static ?string $modelLabel = '會員等級';

    protected static ?string $pluralModelLabel = '會員等級';

    protected static ?string $slug = 'membership-levels';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('levels.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('等級名稱')->required()->maxLength(50),
            Textarea::make('benefits')->label('待遇說明（顯示於 App）')->rows(5)->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('代碼'),
                TextColumn::make('name')->label('名稱'),
                TextColumn::make('is_default')->label('預設')
                    ->state(fn (MembershipLevel $record): string => $record->is_default ? '是（無有效等級時）' : '否'),
                TextColumn::make('benefits')->label('待遇')->limit(60)->placeholder('—'),
            ])
            ->defaultSort('rank')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipLevels::route('/'),
            'edit' => EditMembershipLevel::route('/{record}/edit'),
        ];
    }
}
