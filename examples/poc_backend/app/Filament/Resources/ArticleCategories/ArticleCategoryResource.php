<?php

namespace App\Filament\Resources\ArticleCategories;

use App\Filament\Resources\ArticleCategories\Pages\CreateArticleCategory;
use App\Filament\Resources\ArticleCategories\Pages\EditArticleCategory;
use App\Filament\Resources\ArticleCategories\Pages\ListArticleCategories;
use App\Models\ArticleCategory;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * S8 M05 article categories, up to three levels. A category with sub-categories or
 * articles cannot be deleted.
 */
class ArticleCategoryResource extends Resource
{
    protected static ?string $model = ArticleCategory::class;

    protected static ?string $modelLabel = '文章分類';

    protected static ?string $pluralModelLabel = '文章分類';

    protected static ?string $slug = 'article-categories';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('articles.manage') ?? false;
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
        return false; // Only through the guarded action below.
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')->label('上層分類（空白＝第一層）')
                ->options(fn (?ArticleCategory $record): array => ArticleCategory::query()
                    ->where('depth', '<', ArticleCategory::MAX_DEPTH)
                    ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->id))
                    ->get()->mapWithKeys(fn (ArticleCategory $c): array => [$c->id => $c->path()])->all()),
            TextInput::make('name')->label('名稱')->required()->maxLength(50),
            TextInput::make('sort')->label('排序（小的在前）')->numeric()->minValue(0)->maxValue(65535)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('path')->label('分類')->state(fn (ArticleCategory $record): string => $record->path()),
                TextColumn::make('depth')->label('層級')->sortable(),
                TextColumn::make('sort')->label('排序')->sortable(),
                TextColumn::make('articles_count')->label('文章數')->counts('articles'),
            ])
            ->defaultSort('id')
            ->recordActions([
                EditAction::make(),
                Action::make('deleteCategory')
                    ->label('刪除')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (ArticleCategory $record): void {
                        try {
                            $record->delete();
                            Notification::make()->title('分類已刪除')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('無法刪除')->body(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticleCategories::route('/'),
            'create' => CreateArticleCategory::route('/create'),
            'edit' => EditArticleCategory::route('/{record}/edit'),
        ];
    }
}
