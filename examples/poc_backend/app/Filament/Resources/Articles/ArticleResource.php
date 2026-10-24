<?php

namespace App\Filament\Resources\Articles;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Filament\Resources\Articles\RelationManagers\LikesRelationManager;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\Article;
use App\Models\ArticleAccessRule;
use App\Models\ArticleCategory;
use App\Models\MembershipLevel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Spatie\Permission\Models\Role;

/**
 * S8 M05-A 文章管理: rich text, categories (≥1 to publish or schedule), cover upload
 * or URL, display author (creator id kept), visibility (public / members / level
 * or role, OR), publish now / scheduled / auto take-down / withdraw, trash.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $modelLabel = '文章';

    protected static ?string $pluralModelLabel = '文章管理';

    protected static ?string $slug = 'articles';

    protected static ?int $navigationSort = 6;

    public const VISIBILITY_LABELS = [
        Article::VISIBILITY_PUBLIC => '公開',
        Article::VISIBILITY_MEMBERS => '會員',
        Article::VISIBILITY_RESTRICTED => '指定會員等級或角色',
    ];

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
        return static::canViewAny();
    }

    public static function canRestore(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    /**
     * @return Builder<Article>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** 草稿 / 排程中 / 已發布 / 已下架 / 【已撤回】 / 【垃圾桶】 */
    public static function stateLabel(Article $a): string
    {
        $now = now();

        return match (true) {
            $a->trashed() => '【垃圾桶】',
            $a->status === Article::STATUS_DRAFT => '草稿',
            $a->status === Article::STATUS_WITHDRAWN => '【已撤回】',
            $a->published_at !== null && $a->published_at->gt($now) => '排程中',
            $a->unpublish_at !== null && $a->unpublish_at->lte($now) => '已下架',
            default => '已發布',
        };
    }

    public static function form(Schema $schema): Schema
    {
        $tz = CalendarEventResource::displayTimezone();

        return $schema->components([
            TextInput::make('title')->label('標題')->required()->maxLength(255),
            RichEditor::make('body_html')->label('內文')->required()->columnSpanFull(),
            Textarea::make('summary')->label('摘要')->rows(3)->maxLength(1000),
            Select::make('categories')->label('分類（發布或排程時至少一個）')
                ->relationship('categories', 'name')
                ->getOptionLabelFromRecordUsing(fn (ArticleCategory $record): string => $record->path())
                ->multiple()->preload()
                ->required(fn (Get $get): bool => $get('status') === Article::STATUS_PUBLISHED),
            FileUpload::make('cover_path')->label('封面上傳')->image()->disk('local')->directory('article-covers')
                ->visibility('private')->maxSize(5120),
            TextInput::make('cover_url')->label('或封面外部網址')->url()->maxLength(2048)
                ->helperText('有上傳封面時以上傳的為準。'),
            TextInput::make('author_name')->label('作者（對外顯示）')->required()->maxLength(80)
                ->default(fn (): string => (string) auth()->user()?->name),
            Select::make('visibility')->label('閱讀權限')->options(self::VISIBILITY_LABELS)
                ->default(Article::VISIBILITY_PUBLIC)->required()->live(),
            Select::make('access_levels')->label('可閱讀的會員等級')->multiple()
                ->options(fn (): array => MembershipLevel::query()->orderBy('rank')->pluck('name', 'code')->all())
                ->visible(fn (Get $get): bool => $get('visibility') === Article::VISIBILITY_RESTRICTED)
                ->dehydrated(false),
            Select::make('access_roles')->label('或可閱讀的角色')->multiple()
                ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                ->visible(fn (Get $get): bool => $get('visibility') === Article::VISIBILITY_RESTRICTED)
                ->helperText('符合任一等級或任一角色即可閱讀。')
                ->dehydrated(false),
            Select::make('status')->label('狀態')
                ->options([
                    Article::STATUS_DRAFT => '草稿',
                    Article::STATUS_PUBLISHED => '發布（未來時間＝排程）',
                    Article::STATUS_WITHDRAWN => '撤回',
                ])
                ->default(Article::STATUS_DRAFT)->required()->live(),
            DateTimePicker::make('published_at')->label('發布時間（空白＝立即）')->seconds(false)->timezone($tz),
            DateTimePicker::make('unpublish_at')->label('自動下架時間（選填）')->seconds(false)->timezone($tz)
                ->after('published_at'),
            Toggle::make('is_featured')->label('精選'),
            TextInput::make('featured_order')->label('精選排序（小的在前）')->numeric()->minValue(0)->maxValue(65535)->default(0),
        ]);
    }

    /**
     * Saves the restricted-visibility rules (levels OR roles) from the form state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function saveAccessRules(Article $article, array $state): void
    {
        $article->accessRules()->delete();

        if ($article->visibility !== Article::VISIBILITY_RESTRICTED) {
            return;
        }

        foreach ((array) ($state['access_levels'] ?? []) as $code) {
            $article->accessRules()->create(['type' => ArticleAccessRule::TYPE_LEVEL, 'value' => (string) $code]);
        }
        foreach ((array) ($state['access_roles'] ?? []) as $role) {
            $article->accessRules()->create(['type' => ArticleAccessRule::TYPE_ROLE, 'value' => (string) $role]);
        }
    }

    public static function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('標題')->searchable()->limit(40),
                TextColumn::make('categories.name')->label('分類')->badge()->placeholder('—'),
                TextColumn::make('state')->label('狀態')->state(fn (Article $record): string => static::stateLabel($record)),
                TextColumn::make('visibility')->label('權限')
                    ->formatStateUsing(fn (string $state): string => self::VISIBILITY_LABELS[$state] ?? $state),
                TextColumn::make('is_featured')->label('精選')
                    ->state(fn (Article $record): string => $record->is_featured ? '★ '.$record->featured_order : ''),
                TextColumn::make('view_count')->label('瀏覽')->sortable(),
                TextColumn::make('like_count')->label('讚')->sortable(),
                TextColumn::make('published_at')->label('發布')->dateTime('Y-m-d H:i', $tz)->sortable(),
                TextColumn::make('author_name')->label('作者')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('狀態')
                    ->options([Article::STATUS_DRAFT => '草稿', Article::STATUS_PUBLISHED => '發布', Article::STATUS_WITHDRAWN => '撤回']),
                SelectFilter::make('visibility')->label('權限')->options(self::VISIBILITY_LABELS),
                TrashedFilter::make()->label('垃圾桶'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (Article $record): bool => ! $record->trashed()),
                Action::make('publishNow')
                    ->label('立即發布')
                    ->visible(fn (Article $record): bool => ! $record->trashed() && $record->status !== Article::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->action(function (Article $record): void {
                        if (! $record->categories()->exists()) {
                            Notification::make()->title('發布前請至少選擇一個分類')->danger()->send();

                            return;
                        }
                        $record->update(['status' => Article::STATUS_PUBLISHED, 'published_at' => now()]);
                        Notification::make()->title('文章已發布')->success()->send();
                    }),
                Action::make('withdraw')
                    ->label('撤回')
                    ->visible(fn (Article $record): bool => ! $record->trashed() && $record->status === Article::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->action(function (Article $record): void {
                        $record->update(['status' => Article::STATUS_WITHDRAWN]);
                        Notification::make()->title('文章已撤回')->success()->send();
                    }),
                DeleteAction::make()->label('移到垃圾桶'),
                RestoreAction::make()->label('還原'),
                ForceDeleteAction::make()->label('永久刪除'),
            ]);
    }

    public static function getRelations(): array
    {
        return [LikesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
