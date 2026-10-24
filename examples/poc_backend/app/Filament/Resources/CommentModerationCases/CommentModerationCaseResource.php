<?php

namespace App\Filament\Resources\CommentModerationCases;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\CommentModerationCases\Pages\EditCommentModerationCase;
use App\Filament\Resources\CommentModerationCases\Pages\ListCommentModerationCases;
use App\Filament\Resources\CommentModerationCases\RelationManagers\ReportsRelationManager;
use App\Models\ArticleComment;
use App\Models\CommentModerationCase;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * S8 M05-B05 report rounds. The edit page (linked from the admin email) shows the
 * comment, the reports and the AI verdict; the admin restores, keeps hidden,
 * deletes or closes, which ends the round.
 */
class CommentModerationCaseResource extends Resource
{
    protected static ?string $model = CommentModerationCase::class;

    protected static ?string $modelLabel = '檢舉案件';

    protected static ?string $pluralModelLabel = '留言檢舉案件';

    protected static ?string $slug = 'comment-moderation-cases';

    protected static ?int $navigationSort = 9;

    public const AI_LABELS = [
        'none' => '未達門檻',
        'pending' => '審核中',
        'done' => '已審核',
        'failed' => '無法使用（已暫時隱藏）',
    ];

    public const DECISION_LABELS = ['keep' => '保留公開', 'hide' => '暫時隱藏', 'unsure' => '無法判定（暫時隱藏）'];

    public const RESOLUTION_LABELS = ['restored' => '恢復公開', 'kept_hidden' => '維持隱藏', 'deleted' => '刪除留言', 'closed' => '結案'];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('comments.moderate') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
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
        $tz = CalendarEventResource::displayTimezone();

        return $schema->components([
            TextEntry::make('article')->label('文章')
                ->state(fn (CommentModerationCase $record): string => (string) $record->comment?->article?->title),
            TextEntry::make('comment_body')->label('被檢舉留言')
                ->state(fn (CommentModerationCase $record): string => (string) $record->comment?->body)->columnSpanFull(),
            TextEntry::make('comment_author')->label('留言會員')
                ->state(fn (CommentModerationCase $record): string => '#'.$record->comment?->user_id.' '.$record->comment?->user?->name),
            TextEntry::make('comment_status')->label('留言目前狀態')
                ->state(fn (CommentModerationCase $record): string => match ($record->comment?->status) {
                    ArticleComment::STATUS_VISIBLE => '公開',
                    ArticleComment::STATUS_HIDDEN => '隱藏',
                    ArticleComment::STATUS_DELETED => '已刪除',
                    default => '—',
                }),
            TextEntry::make('round')->label('輪次'),
            TextEntry::make('report_count')->label('檢舉人數'),
            TextEntry::make('ai')->label('AI 審核')
                ->state(fn (CommentModerationCase $record): string => (self::AI_LABELS[$record->ai_status] ?? $record->ai_status)
                    .($record->ai_decision ? '：'.(self::DECISION_LABELS[$record->ai_decision] ?? $record->ai_decision) : '')),
            TextEntry::make('ai_reason')->label('AI 說明')->placeholder('—'),
            TextEntry::make('resolution')->label('處理結果')
                ->state(fn (CommentModerationCase $record): string => $record->resolution
                    ? (self::RESOLUTION_LABELS[$record->resolution] ?? $record->resolution).'（'.$record->resolved_at?->timezone($tz)->format('Y-m-d H:i').'）'
                    : '未結案'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('comment.body')->label('留言')->limit(40),
                TextColumn::make('round')->label('輪次'),
                TextColumn::make('report_count')->label('檢舉人數')->sortable(),
                TextColumn::make('ai_status')->label('AI')->formatStateUsing(fn (string $state): string => self::AI_LABELS[$state] ?? $state),
                TextColumn::make('ai_decision')->label('AI 判斷')
                    ->formatStateUsing(fn (?string $state): string => $state ? (self::DECISION_LABELS[$state] ?? $state) : '—')->placeholder('—'),
                TextColumn::make('resolution')->label('處理')
                    ->formatStateUsing(fn (?string $state): string => $state ? (self::RESOLUTION_LABELS[$state] ?? $state) : '')->placeholder('未結案'),
                TextColumn::make('updated_at')->label('更新')->dateTime('Y-m-d H:i', $tz)->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TernaryFilter::make('open')->label('未結案')
                    ->queries(
                        true: fn (Builder $q) => $q->where('active', 1),
                        false: fn (Builder $q) => $q->whereNull('active'),
                    ),
            ])
            ->recordActions([EditAction::make()->label('處理')]);
    }

    public static function getRelations(): array
    {
        return [ReportsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommentModerationCases::route('/'),
            'edit' => EditCommentModerationCase::route('/{record}/edit'),
        ];
    }
}
