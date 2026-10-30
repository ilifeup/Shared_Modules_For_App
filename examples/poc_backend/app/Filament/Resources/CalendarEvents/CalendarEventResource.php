<?php

namespace App\Filament\Resources\CalendarEvents;

use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\CalendarEvents\RelationManagers\AttendanceRelationManager;
use App\Filament\Resources\CalendarEvents\RelationManagers\BookingGroupsRelationManager;
use App\Filament\Resources\CalendarEvents\RelationManagers\BookingsRelationManager;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\Tag;
use App\Services\Booking\BookingEngine;
use App\Services\Calendar\EventSeriesService;
use App\Services\Content\TagService;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

    /** Common IANA zones; any valid zone can be stored (M09-D21). */
    public const TIMEZONES = [
        'Asia/Taipei' => 'Asia/Taipei（台北）',
        'Asia/Hong_Kong' => 'Asia/Hong_Kong',
        'Asia/Shanghai' => 'Asia/Shanghai',
        'Asia/Tokyo' => 'Asia/Tokyo',
        'Asia/Singapore' => 'Asia/Singapore',
        'America/Los_Angeles' => 'America/Los_Angeles',
        'America/New_York' => 'America/New_York',
        'Europe/London' => 'Europe/London',
        'UTC' => 'UTC',
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
            Select::make('event_category_id')->label('活動類別（顏色、報名對象）')
                ->options(fn (): array => EventCategory::query()->orderBy('sort')->pluck('name', 'id')->all())->nullable(),
            Select::make('tag_ids')->label('標籤（最多 10 個）')->multiple()->maxItems(Tag::MAX_PER_CONTENT)
                ->options(fn (): array => Tag::query()->where('is_active', true)->orderBy('sort')->pluck('name', 'id')->all())
                ->visible(fn (): bool => app(TagService::class)->enabledFor('event'))
                ->dehydrated(false),
            // S9: one venue set per occurrence; every part optional.
            TextInput::make('location')->label('地點（Location，選填）')->maxLength(255),
            TextInput::make('floor')->label('樓層（選填）')->maxLength(255),
            TextInput::make('studio')->label('Studio（選填）')->maxLength(255),
            Select::make('timezone')->label('時區')->options(self::TIMEZONES)->default('Asia/Taipei')->required(),
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
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled'))
                // Existing bookings: change through 「調整名額」 so overbooking is impossible (M08-D20).
                ->disabled(fn (?CalendarEvent $record): bool => $record?->booking_capacity !== null)
                ->helperText(fn (?CalendarEvent $record): ?string => $record?->booking_capacity !== null ? '已建立的活動請使用清單的「調整名額」。' : null),
            TextInput::make('waitlist_capacity')->label('候補名額上限')->numeric()->minValue(0)->maxValue(100000)->default(5)
                ->required()
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            TextInput::make('booking_max_seats')->label('每筆報名人數（固定 1 人）')->numeric()->minValue(1)->maxValue(1)->default(1)
                ->disabled()->dehydrated()->visible(false),
            Toggle::make('waitlist_enabled')->label('額滿開放候補（依會員報名順序遞補）')->default(true)
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            TextInput::make('price_amount')->label('活動費用（TWD，0＝免費）')
                ->numeric()->minValue(0)->default(0)->required()
                ->helperText('MVP 僅線下現金收款；付費活動需設定足夠的付款保留時間。'),
            TextInput::make('payment_hold_hours')->label('付款保留時間（小時）')
                ->numeric()->minValue(1)->maxValue(8760)
                ->placeholder(fn (): string => (string) app(Settings::class)->int('payment_hold_hours'))
                ->helperText('留空使用系統管理員設定的預設時數。付費活動的最後預約時間＋保留時間不得晚於活動開始時間。'),
            TextInput::make('booking_close_offset_minutes')->label('預約截止：活動開始前 N 分鐘（相對期限，改期自動重算）')->numeric()->minValue(0)->maxValue(525600)
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            TextInput::make('cancel_offset_minutes')->label('取消期限：活動開始前 N 分鐘（相對）')->numeric()->minValue(0)->maxValue(525600)
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            DateTimePicker::make('cancel_closes_at')->label('或固定取消期限（空白＝活動開始前皆可取消）')->seconds(false)
                ->timezone(static::displayTimezone())
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            DateTimePicker::make('booking_opens_at')->label('預約開放時間（空白＝立即）')->seconds(false)
                ->timezone(static::displayTimezone())
                ->visible(fn (Get $get): bool => (bool) $get('booking_enabled')),
            DateTimePicker::make('booking_closes_at')->label('停止預約時間（空白＝活動開始時）')->seconds(false)
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
            // S9 M11 check-in.
            Section::make('簽到設定')->columns(2)->schema([
                Toggle::make('checkin_enabled')->label('開放簽到')->live(),
                Toggle::make('checkout_enabled')->label('啟用簽退'),
                TextInput::make('checkin_open_before_minutes')->label('活動開始前幾分鐘開放簽到')->numeric()->minValue(0)->maxValue(1440)->default(60),
                TextInput::make('checkin_close_after_end_minutes')->label('活動結束後幾分鐘截止簽到')->numeric()->minValue(0)->maxValue(1440)->default(0),
                Select::make('checkin_gps_required')->label('地點 QR 需要 GPS')
                    ->options(['' => '依 App 預設', '1' => '需要', '0' => '不需要'])
                    ->formatStateUsing(fn ($state): string => $state === null ? '' : ((bool) $state ? '1' : '0'))
                    ->dehydrateStateUsing(fn ($state): ?bool => $state === '' || $state === null ? null : (bool) (int) $state),
                TextInput::make('checkin_gps_radius_m')->label('允許距離（公尺，空白＝App 預設）')->numeric()->minValue(10)->maxValue(5000),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = static::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('title')->label('活動')->searchable()->limit(40),
                TextColumn::make('location')->label('地點')->searchable()->placeholder('—')
                    ->state(fn (CalendarEvent $record): string => implode(' ', array_filter([$record->location, $record->floor, $record->studio]))),
                TextColumn::make('category.name')->label('類別')->placeholder('—'),
                TextColumn::make('event_series_id')->label('系列')
                    ->state(fn (CalendarEvent $record): string => $record->event_series_id === null ? '' : '系列 #'.$record->event_series_id.($record->is_series_exception ? '（單次例外）' : ''))
                    ->placeholder('—'),
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
                        // M08-D12: bookings and waitlist end with the occurrence; contacts are notified.
                        app(BookingEngine::class)->cancelEvent($record, $data['cancel_reason'] ?? null, auth()->user());

                        Notification::make()->title('活動已取消，報名與候補已一併取消並通知')->success()->send();
                    }),
                Action::make('adjustCapacity')
                    ->label('調整名額')
                    ->visible(fn (CalendarEvent $record): bool => $record->booking_enabled && $record->status !== CalendarEvent::STATUS_CANCELLED)
                    ->schema([
                        TextInput::make('capacity')->label('新名額')->numeric()->minValue(1)->maxValue(100000)->required()
                            ->default(fn (CalendarEvent $record): int => (int) $record->booking_capacity),
                        CheckboxList::make('cancel_groups')->label('名額不足時要取消的報名（M08-D20）')
                            ->options(fn (CalendarEvent $record): array => EventBookingGroup::query()->where('calendar_event_id', $record->id)
                                ->where('status', EventBookingGroup::STATUS_CONFIRMED)->orderByDesc('booked_at')->get()
                                ->mapWithKeys(fn (EventBookingGroup $g) => [$g->id => '#'.$g->id.' '.$g->contact_name.'（'.$g->seat_count.' 人）'])->all()),
                        TextInput::make('reason')->label('原因（會通知被取消者）')->maxLength(255),
                    ])
                    ->action(function (CalendarEvent $record, array $data): void {
                        try {
                            app(BookingEngine::class)->changeCapacity($record, (int) $data['capacity'], array_map('intval', $data['cancel_groups'] ?? []), auth()->user(), $data['reason'] ?? null);
                            Notification::make()->title('名額已調整')->success()->send();
                        } catch (ApiException $e) {
                            Notification::make()->title($e->getMessage())->body(collect($e->fields)->flatten()->implode(' '))->danger()->send();
                        }
                    }),
                Action::make('editSeries')
                    ->label('修改系列')
                    ->visible(fn (CalendarEvent $record): bool => $record->event_series_id !== null && $record->status !== CalendarEvent::STATUS_CANCELLED)
                    ->schema(fn (): array => SeriesForms::batchFields())
                    ->fillForm(fn (CalendarEvent $record): array => SeriesForms::fill($record))
                    ->action(fn (CalendarEvent $record, array $data) => SeriesForms::applyBatch($record, $data)),
                Action::make('cancelSeries')
                    ->label('取消系列場次')
                    ->color('danger')
                    ->visible(fn (CalendarEvent $record): bool => $record->event_series_id !== null && $record->status !== CalendarEvent::STATUS_CANCELLED)
                    ->requiresConfirmation()
                    ->modalDescription('已開始或已結束的場次不受影響（S9-L01）。')
                    ->schema([
                        Select::make('scope')->label('範圍')->options(['future' => '此次及未來', 'all' => '全部未開始場次'])->required()->default('future'),
                        TextInput::make('cancel_reason')->label('取消原因（選填）')->maxLength(255),
                    ])
                    ->action(function (CalendarEvent $record, array $data): void {
                        $ids = app(EventSeriesService::class)->cancel($record, $data['scope'], $data['cancel_reason'] ?? null, auth()->user());
                        Notification::make()->title('已取消 '.count($ids).' 個場次')->success()->send();
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

        // S9: seats = real attendees across S6 and S9 bookings (one ledger).
        $engine = app(BookingEngine::class);
        $booked = $engine->confirmedSeats($record);
        $capacity = (int) $record->booking_capacity;
        $waiting = $engine->waitingGroups($record);

        return "已預約 {$booked} / {$capacity}（剩 ".max(0, $capacity - $booked).'）'.($waiting > 0 ? "｜候補 {$waiting} 人" : '');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveTags(CalendarEvent $event, array $state): void
    {
        if (array_key_exists('tag_ids', $state) && app(TagService::class)->enabledFor('event')) {
            app(TagService::class)->sync('event', (int) $event->id, array_map('intval', (array) $state['tag_ids']), auth()->user());
        }
    }

    public static function getRelations(): array
    {
        return [
            BookingGroupsRelationManager::class,
            AttendanceRelationManager::class,
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
