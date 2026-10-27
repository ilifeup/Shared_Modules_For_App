<?php

namespace App\Filament\Resources\Notices;

use App\Filament\Resources\Notices\Pages\CreateNotice;
use App\Filament\Resources\Notices\Pages\EditNotice;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Notice;
use App\Models\NoticeCategory;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static ?string $modelLabel = '公告';

    protected static ?string $pluralModelLabel = '公告管理';

    protected static ?int $navigationSort = 3;

    public const STATUS_LABELS = [
        Notice::STATUS_DRAFT => '草稿',
        Notice::STATUS_PUBLISHED => '已發布',
        Notice::STATUS_WITHDRAWN => '【已撤回】',
    ];

    public const AUDIENCE_LABELS = [
        Notice::AUDIENCE_MEMBERS => '僅會員',
        Notice::AUDIENCE_PUBLIC => '公開（未登入也可看）',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('notices.manage') ?? false;
    }

    /**
     * Only drafts can be deleted; published notices are withdrawn instead (keeps read records).
     */
    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && $record instanceof Notice && $record->status === Notice::STATUS_DRAFT;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('標題')->required()->maxLength(255),
            Textarea::make('body')->label('內容')->required()->rows(10)->columnSpanFull(),
            Select::make('notice_category_id')->label('分類')
                ->options(fn (): array => NoticeCategory::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id')->all())
                ->placeholder('（未分類）'),
            Select::make('audience')->label('對象')
                ->options(self::AUDIENCE_LABELS)
                ->default(Notice::AUDIENCE_MEMBERS)
                ->required(),
            Select::make('status')->label('狀態')
                ->options([
                    Notice::STATUS_DRAFT => '草稿（看不到）',
                    Notice::STATUS_PUBLISHED => '發布',
                    Notice::STATUS_WITHDRAWN => '撤回（不再顯示）',
                ])
                ->default(Notice::STATUS_DRAFT)
                ->required(),
            Toggle::make('is_pinned')->label('置頂'),
            DateTimePicker::make('published_at')->label('上架時間')
                ->helperText('留空並選「發布」時，以儲存當下為上架時間；設定未來時間則到時才顯示。'),
            DateTimePicker::make('publish_ends_at')->label('下架時間（選填）')
                ->after('published_at')
                ->helperText('到此時間後自動不再顯示。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('標題')->searchable()->limit(40),
                TextColumn::make('category.name')->label('分類')->placeholder('—'),
                TextColumn::make('is_pinned')->label('置頂')
                    ->state(fn (Notice $record): string => $record->is_pinned ? '📌 置頂' : ''),
                TextColumn::make('audience')->label('對象')
                    ->formatStateUsing(fn (string $state): string => self::AUDIENCE_LABELS[$state] ?? $state),
                TextColumn::make('status')->label('狀態')
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('published_at')->label('上架')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('publish_ends_at')->label('下架')->dateTime('Y-m-d H:i')->placeholder('不限'),
                TextColumn::make('reads_count')->label('已讀人數')->counts('reads'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('狀態')->options(self::STATUS_LABELS),
                SelectFilter::make('notice_category_id')->label('分類')->relationship('category', 'name'),
                SelectFilter::make('audience')->label('對象')->options(self::AUDIENCE_LABELS),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('withdraw')
                    ->label('撤回')
                    ->visible(fn (Notice $record): bool => $record->status === Notice::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->modalDescription('撤回後會員與訪客都看不到此公告，已讀紀錄保留。')
                    ->action(function (Notice $record): void {
                        $record->update(['status' => Notice::STATUS_WITHDRAWN]);

                        Notification::make()->title('公告已撤回')->success()->send();
                    }),
                DeleteAction::make()->visible(fn (Notice $record): bool => $record->status === Notice::STATUS_DRAFT),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotices::route('/'),
            'create' => CreateNotice::route('/create'),
            'edit' => EditNotice::route('/{record}/edit'),
        ];
    }
}
