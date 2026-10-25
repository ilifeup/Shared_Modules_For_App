<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Tag;
use App\Services\Content\TagService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * S9 M16 標籤管理 (tags.manage only, D02, D18): single level, normalized unique
 * names, automatic colours with manual override, order, usage counts, enable /
 * disable, merge, bulk actions with a delete preview, CSV import / export.
 */
class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $modelLabel = '標籤';

    protected static ?string $pluralModelLabel = '標籤管理';

    protected static ?string $slug = 'tags';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('tags.manage') ?? false;
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
        return false; // through the confirmed delete actions (preview + audit)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('名稱')->required()->maxLength(50),
            ColorPicker::make('color')->label('顏色（空白＝系統自動配色）')->regex('/^#[0-9A-Fa-f]{6}$/'),
            TextInput::make('sort')->label('排序（小的在前）')->numeric()->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label('啟用')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('名稱')->searchable(query: fn ($query, string $search) => $query->where('normalized_name', 'like', '%'.Tag::normalize($search).'%')),
                TextColumn::make('color_display')->label('顏色')->state(fn (Tag $r): string => '■ '.$r->displayColor().($r->color === null ? '（自動）' : '')),
                TextColumn::make('sort')->label('排序')->sortable(),
                TextColumn::make('usage')->label('關聯內容數')->state(fn (Tag $r): int => $r->usageCount()),
                TextColumn::make('is_active')->label('狀態')->formatStateUsing(fn ($state): string => $state ? '啟用' : '【停用】'),
            ])
            ->defaultSort('sort')
            ->filters([TernaryFilter::make('is_active')->label('啟用')])
            ->recordActions([
                EditAction::make(),
                Action::make('merge')->label('合併到…')
                    ->schema([Select::make('target')->label('目標標籤')->required()
                        ->options(fn (Tag $record): array => Tag::query()->whereKeyNot($record->id)->orderBy('name')->pluck('name', 'id')->all())])
                    ->requiresConfirmation()
                    ->modalDescription('來源標籤的內容關聯會轉到目標標籤（重複的不重複建立），完成後刪除來源標籤；內容不受影響。')
                    ->action(function (Tag $record, array $data): void {
                        $moved = app(TagService::class)->merge($record, Tag::query()->findOrFail($data['target']), auth()->user());
                        Notification::make()->title('已合併，轉移 '.$moved.' 筆關聯')->success()->send();
                    }),
                Action::make('deleteTag')->label('刪除')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Tag $record): string => self::previewText([$record->id]))
                    ->action(function (Tag $record): void {
                        app(TagService::class)->delete([$record->id], auth()->user());
                        Notification::make()->title('標籤已刪除（內容保留）')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('enable')->label('批次啟用')->requiresConfirmation()
                    ->action(fn (Collection $records) => self::bulk($records, true)),
                BulkAction::make('disable')->label('批次停用')->requiresConfirmation()
                    ->action(fn (Collection $records) => self::bulk($records, false)),
                BulkAction::make('bulkDelete')->label('批次刪除')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Collection $records): string => self::previewText($records->modelKeys()))
                    ->schema([TextInput::make('confirm')->label('請輸入「刪除」再次確認')->required()->in(['刪除'])])
                    ->action(function (Collection $records): void {
                        $result = app(TagService::class)->delete($records->modelKeys(), auth()->user());
                        Notification::make()->title('已刪除 '.$result['tags'].' 個標籤、'.$result['relations'].' 筆關聯（內容保留）')->success()->send();
                    }),
            ]);
    }

    /**
     * @param  list<int>  $ids
     */
    public static function previewText(array $ids): string
    {
        $p = app(TagService::class)->deletePreview($ids);

        return '將刪除 '.$p['tags'].' 個標籤（'.implode('、', array_slice($p['names'], 0, 20)).'）及 '.$p['relations'].' 筆內容關聯；文章與活動本身不會被刪除。';
    }

    private static function bulk(Collection $records, bool $active): void
    {
        $count = app(TagService::class)->setActive($records->modelKeys(), $active, auth()->user());
        Notification::make()->title(($active ? '已啟用 ' : '已停用 ').$count.' 個標籤')->success()->send();
    }

    /**
     * Saves a create / edit form through TagService (normalized uniqueness, colour check, audit).
     *
     * @param  array<string, mixed>  $data
     */
    public static function persist(?Tag $tag, array $data): Tag
    {
        $service = app(TagService::class);
        try {
            return $tag === null
                ? $service->create((string) $data['name'], $data['color'] ?? null, (int) ($data['sort'] ?? 0), auth()->user())
                : $service->update($tag, ['name' => (string) $data['name'], 'color' => $data['color'] ?? null, 'sort' => (int) ($data['sort'] ?? 0), 'is_active' => (bool) ($data['is_active'] ?? true)], auth()->user());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all());
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
        ];
    }
}
