<?php

namespace App\Filament\Resources\CalendarEvents\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Services\Checkin\CheckinService;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M11 attendance of one occurrence (D18): booked, checked in, absent, checked out;
 * admin check-in / revoke / correction with reasons (D12, D16, D27).
 */
class AttendanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attendees';

    protected static ?string $title = '簽到名單';

    public const LABELS = [
        EventAttendance::CHECKED_IN => '已簽到',
        EventAttendance::CHECKED_OUT => '已簽退',
        EventAttendance::REVOKED => '【已撤銷】',
    ];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('checkin.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->heading(function (): string {
                $s = app(CheckinService::class)->summary($this->getOwnerRecord());

                return "簽到名單（報名 {$s['booked']}、已簽到 {$s['checked_in']}、未到 {$s['absent']}".($s['checkout_enabled'] ? "、已簽退 {$s['checked_out']}" : '').'）';
            })
            ->modifyQueryUsing(fn ($query) => $query->with('attendance', 'group')->where('status', EventBookingAttendee::STATUS_CONFIRMED))
            ->columns([
                TextColumn::make('name')->label('參加者')->searchable(),
                TextColumn::make('group.contact_name')->label('報名聯絡人'),
                TextColumn::make('checkin')->label('狀態')
                    ->state(fn (EventBookingAttendee $r): string => $r->attendance ? (self::LABELS[$r->attendance->status] ?? $r->attendance->status) : '未到'),
                TextColumn::make('attendance.checked_in_at')->label('簽到時間')->dateTime('Y-m-d H:i', $tz)->placeholder('—'),
                TextColumn::make('attendance.checked_out_at')->label('簽退時間')->dateTime('Y-m-d H:i', $tz)->placeholder('—'),
                TextColumn::make('attendance.source')->label('方式')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('present')->label('出席')->options(['yes' => '已簽到', 'no' => '未到'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'yes' => $query->whereHas('attendance', fn ($q) => $q->whereIn('status', [EventAttendance::CHECKED_IN, EventAttendance::CHECKED_OUT])),
                        'no' => $query->whereDoesntHave('attendance', fn ($q) => $q->whereIn('status', [EventAttendance::CHECKED_IN, EventAttendance::CHECKED_OUT])),
                        default => $query,
                    }),
            ])
            ->recordActions([
                $this->setAction('adminCheckIn', '簽到／補登', EventAttendance::CHECKED_IN),
                $this->setAction('adminCheckOut', '簽退', EventAttendance::CHECKED_OUT),
                $this->setAction('adminRevoke', '撤銷簽到', EventAttendance::REVOKED),
            ]);
    }

    private function setAction(string $name, string $label, string $status): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([TextInput::make('reason')->label('原因（逾時補登、撤銷或更正必填）')->maxLength(255)])
            ->action(function (EventBookingAttendee $record, array $data) use ($status, $label): void {
                /** @var CalendarEvent $event */
                $event = $this->getOwnerRecord();
                try {
                    app(CheckinService::class)->adminSet($event, $record, $status, auth()->user(), $data['reason'] ?? null);
                    Notification::make()->title('已'.$label)->success()->send();
                } catch (ApiException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
