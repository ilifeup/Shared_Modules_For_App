<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Models\AuditEvent;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * M07 audit log (read-only). There is no create, edit or delete page or action;
 * the model itself also refuses updates and deletes.
 */
class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static ?string $modelLabel = '稽核紀錄';

    protected static ?string $pluralModelLabel = '稽核紀錄';

    protected static ?string $slug = 'audit-events';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function detailsText(AuditEvent $record): string
    {
        if ($record->details === null || $record->details === []) {
            return '—';
        }

        return (string) json_encode($record->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('時間')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event')->label('事件')->searchable(),
                TextColumn::make('actor.name')->label('操作者')->placeholder('（系統／訪客）'),
                TextColumn::make('subject')->label('對象')
                    ->state(fn (AuditEvent $record): string => $record->subject_type ? $record->subject_type.' #'.$record->subject_id : '—'),
                TextColumn::make('result')->label('結果')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'success' => '成功',
                        'failure' => '【失敗】',
                        'throttled' => '【已限流】',
                        null => '—',
                        default => $state,
                    }),
                TextColumn::make('details')->label('詳細')
                    ->state(fn (AuditEvent $record): string => static::detailsText($record))
                    ->limit(60),
                TextColumn::make('request_id')->label('request_id')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('起日'),
                        DatePicker::make('until')->label('迄日'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
                SelectFilter::make('event')->label('事件類型')
                    ->options(fn (): array => AuditEvent::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                SelectFilter::make('actor_id')->label('操作者')
                    ->relationship('actor', 'name')
                    ->searchable(),
                SelectFilter::make('result')->label('結果')
                    ->options(['success' => '成功', 'failure' => '失敗', 'throttled' => '已限流']),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('檢視')
                    ->modalHeading(fn (AuditEvent $record): string => $record->event)
                    ->modalContent(fn (AuditEvent $record) => view('filament.audit-event-details', [
                        'record' => $record,
                        'details' => static::detailsText($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('關閉'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
        ];
    }
}
