<?php

namespace App\Filament\Resources\ArticleComments;

use App\Filament\Resources\ArticleComments\Pages\ListArticleComments;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\ArticleComment;
use App\Services\CommentModerationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S8 M05-B04 comments are public immediately; admins can hide, restore or delete
 * afterwards (deleted comments are kept as rows for the audit trail).
 */
class ArticleCommentResource extends Resource
{
    protected static ?string $model = ArticleComment::class;

    protected static ?string $modelLabel = '留言';

    protected static ?string $pluralModelLabel = '文章留言';

    protected static ?string $slug = 'article-comments';

    protected static ?int $navigationSort = 8;

    public const STATUS_LABELS = [
        ArticleComment::STATUS_VISIBLE => '公開',
        ArticleComment::STATUS_HIDDEN => '【隱藏】',
        ArticleComment::STATUS_DELETED => '【已刪除】',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('comments.moderate') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('article.title')->label('文章')->limit(30),
                TextColumn::make('user_id')->label('會員 ID'),
                TextColumn::make('user.name')->label('真實姓名'),
                TextColumn::make('user.nickname')->label('暱稱')->placeholder('—'),
                TextColumn::make('body')->label('內容')->limit(60)->searchable(),
                TextColumn::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('hidden_reason')->label('隱藏原因')->placeholder('—'),
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i', $tz)->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->label('狀態')->options(self::STATUS_LABELS)])
            ->recordActions([
                self::statusAction('hide', '隱藏', ArticleComment::STATUS_HIDDEN, fn (ArticleComment $c): bool => $c->status === ArticleComment::STATUS_VISIBLE),
                self::statusAction('restore', '恢復公開', ArticleComment::STATUS_VISIBLE, fn (ArticleComment $c): bool => $c->status !== ArticleComment::STATUS_VISIBLE),
                self::statusAction('delete', '刪除', ArticleComment::STATUS_DELETED, fn (ArticleComment $c): bool => $c->status !== ArticleComment::STATUS_DELETED),
            ]);
    }

    private static function statusAction(string $name, string $label, string $status, \Closure $visible): Action
    {
        return Action::make($name)
            ->label($label)
            ->visible($visible)
            ->requiresConfirmation()
            ->action(function (ArticleComment $record) use ($status, $label): void {
                app(CommentModerationService::class)->setStatus($record, $status, auth()->user());
                Notification::make()->title('已'.$label)->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListArticleComments::route('/')];
    }
}
