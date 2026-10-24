<?php

namespace App\Filament\Resources\CalendarEvents;

use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\CalendarEvents\RelationManagers\BookingsRelationManager;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S5 M09 活動行事曆. Times are entered and shown in APP_DISPLAY_TIMEZONE and stored
 * in UTC. Published events are cancelled (kept), not deleted; only drafts can be deleted.
 */
class CalendarEventResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;

    protected static ?string $modelLabel = '活動';

    protected static ?string $pluralModelLabel = '活動行事曆';

    protected static ?string $slug = 'calendar-events';

    protected static ?int $navigationSort = 5;

    public const STATUS_LABELS = [
        CalendarEvent::STATUS_DRAFT => '草稿',
        CalendarEvent::STATUS_PUBLISHED => '已發布',
        CalendarEvent::STATUS_CANCELLED => '【已取消】',
    ];

    public const AUDIENCE_LABELS = [
        CalendarEvent::AUDIENCE_MEMBERS => '僅會員',
        CalendarEvent::AUDIENCE_PUBLIC => '公開（未登入也可看）',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('events.manage') ?? false;
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
        return static::canViewAny() && $record instanceof CalendarEvent && $record->status === CalendarEvent::STATUS_DRAFT;
    }

    public static function displayTimezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('活動名稱')->required()->maxLength(255),
            Textarea::make('description')->label('說明')->rows(6)->maxLength(5000)->columnSpanFull(),
            TextInput::make('location')->label('地點')->maxLength(255),
            Select::make('audience')->label('對象')->options(self::AUDIENCE_LABELS)
                ->default(CalendarEvent::AUDIENCE_MEMBERS)->required(),
            DateTimePicker::make('starts_at')->label('開始時間')->required()->seconds(false)
                ->timezone(static::displayTimezone()),
            DateTimePicker::make('ends_at')->label('結束時間')->required()->seconds(false)
                ->timezone(static::displayTimezone())
                ->after('starts_at'),
            // S6 M08 booking settings.
            Toggle::make('booking_enabled')->label('開放預約')->live(),
            TextInput::make('booking_capacity')->label('名額上限')->numeric()->minValue(1)->maxValue(100000)
                ->required(fn (Get $get): bool => (bool) $get('booking_enabled'))
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            DateTimePicker::make('booking_opens_at')->label('預約開放時間（空白＝立即）')->seconds(false)
                ->timezone(static::displayTimezone())
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            DateTimePicker::make('booking_closes_at')->label('預約截止時間（空白＝活動開始時）')->seconds(false)
                ->timezone(static::displayTimezone())
                ->after('booking_opens_at')
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            Select::make('status')->label('狀態')
                ->options([
                    CalendarEvent::STATUS_DRAFT => '草稿（會員看不到）',
                    CalendarEvent::STATUS_PUBLISHED => '發布',
                ])
                ->default(CalendarEvent::STATUS_DRAFT)
                ->required()
                ->disabled(fn (?CalendarEvent $record): bool => $record?->status === CalendarEvent::STATUS_CANCELLED)
                ->helperText('取消請使用清單中的「取消活動」，取消後資料保留。'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = static::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('活動')->searchable()->limit(40),
                TextColumn::make('location')->label('地點')->searchable()->placeholder('—'),
                TextColumn::make('starts_at')->label('開始')->dateTime('Y-m-d H:i', $tz)->sortable(),
                TextColumn::make('ends_at')->label('結束')->dateTime('Y-m-d H:i', $tz),
                TextColumn::make('audience')->label('對象')
                    ->formatStateUsing(fn (string $state): string => self::AUDIENCE_LABELS[$state] ?? $state),
                TextColumn::make('booking')->label('預約')
                    ->state(fn (CalendarEvent $record): string => static::bookingSummary($record)),
                TextColumn::make('status')->label('狀態')
                    ->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('狀態')->options(self::STATUS_LABELS),
                SelectFilter::make('audience')->label('對象')->options(self::AUDIENCE_LABELS),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (CalendarEvent $record): bool => $record->status !== CalendarEvent::STATUS_CANCELLED),
                Action::make('publish')
                    ->label('發布')
                    ->visible(fn (CalendarEvent $record): bool => $record->status === CalendarEvent::STATUS_DRAFT)
                    ->requiresConfirmation()
                    ->action(function (CalendarEvent $record): void {
                        $record->update(['status' => CalendarEvent::STATUS_PUBLISHED]);

                        Notification::make()->title('活動已發布')->success()->send();
                    }),
                Action::make('cancelEvent')
                    ->label('取消活動')
                    ->visible(fn (CalendarEvent $record): bool => $record->status === CalendarEvent::STATUS_PUBLISHED)
                    ->requiresConfirmation()
                    ->modalDescription('取消後活動仍會顯示並標示「已取消」，資料保留。')
                    ->schema([
                        TextInput::make('cancel_reason')->label('取消原因（選填，會顯示給會員）')->maxLength(255),
                    ])
                    ->action(function (CalendarEvent $record, array $data): void {
                        $record->cancel($data['cancel_reason'] ?? null);

                        Notification::make()->title('活動已取消')->success()->send();
                    }),
                DeleteAction::make()->visible(fn (CalendarEvent $record): bool => $record->status === CalendarEvent::STATUS_DRAFT),
            ]);
    }

    /**
     * "已預約 12 / 30（剩 18）" or "未開放".
     */
    public static function bookingSummary(CalendarEvent $record): string
    {
        if (! $record->booking_enabled) {
            return '未開放';
        }

        $booked = $record->bookings()->where('status', EventBooking::STATUS_CONFIRMED)->count();
        $capacity = (int) $record->booking_capacity;

        return "已預約 {$booked} / {$capacity}（剩 ".max(0, $capacity - $booked).'）';
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalendarEvents::route('/'),
            'create' => CreateCalendarEvent::route('/create'),
            'edit' => EditCalendarEvent::route('/{record}/edit'),
        ];
    }
}
