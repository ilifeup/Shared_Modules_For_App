<?php

namespace App\Filament\Resources\NoticeCategories;

use App\Filament\Resources\NoticeCategories\Pages\CreateNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\EditNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\ListNoticeCategories;
use App\Models\NoticeCategory;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Notice categories. A category is deactivated rather than deleted, so notices
 * keep their history.
 */
class NoticeCategoryResource extends Resource
{
    protected static ?string $model = NoticeCategory::class;

    protected static ?string $modelLabel = '公告分類';

    protected static ?string $pluralModelLabel = '公告分類';

    protected static ?string $slug = 'notice-categories';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notices.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('名稱')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('sort')->label('排序（小的在前）')->numeric()->minValue(0)->maxValue(65535)->default(0),
            Toggle::make('is_active')->label('啟用')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('名稱')->searchable(),
                TextColumn::make('sort')->label('排序')->sortable(),
                TextColumn::make('is_active')->label('狀態')
                    ->state(fn (NoticeCategory $record): string => $record->is_active ? '啟用' : '【停用】'),
                TextColumn::make('notices_count')->label('公告數')->counts('notices'),
            ])
            ->defaultSort('sort')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNoticeCategories::route('/'),
            'create' => CreateNoticeCategory::route('/create'),
            'edit' => EditNoticeCategory::route('/{record}/edit'),
        ];
    }
}
