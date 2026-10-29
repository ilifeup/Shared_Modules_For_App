<?php

namespace App\Filament\Resources\CalendarEvents\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventWaitlistEntry;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S9 M08 bookings (groups) and waitlist of one occurrence. Admins may book for a
 * a registered member only, cancel and reorder the waitlist; every
 * action goes through BookingEngine and is audited.
 */
class BookingGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookingGroups';

    protected static ?string $title = '報名與候補';

    public const STATUS_LABELS = [
        EventBookingGroup::STATUS_CONFIRMED => '已確認',
        EventBookingGroup::STATUS_WAITLISTED => '【候補】',
        EventBookingGroup::STATUS_CANCELLED => '【已取消】',
        EventBookingGroup::STATUS_EVENT_CANCELLED => '【活動取消】',
    ];

    public function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->heading(fn (): string => '報名與候補（'.CalendarEventResource::bookingSummary($this->getOwnerRecord()).'）')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('contact_name')->label('聯絡人')->searchable()
                    ->description(fn (EventBookingGroup $r): string => '會員'),
                TextColumn::make('seat_count')->label('人數'),
                TextColumn::make('attendees_list')->label('參加者')
                    ->state(fn (EventBookingGroup $r): string => $r->attendees->pluck('name')->implode('、'))->wrap(),
                TextColumn::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => self::STATUS_LABELS[$state] ?? $state),
                TextColumn::make('waitlist')->label('候補順位')
                    ->state(fn (EventBookingGroup $r): string => $r->status === EventBookingGroup::STATUS_WAITLISTED && $r->waitlistEntry
                        ? (string) EventWaitlistEntry::query()->where('calendar_event_id', $r->calendar_event_id)->where('status', EventWaitlistEntry::WAITING)->where('sequence', '<=', $r->waitlistEntry->sequence)->count()
                        : '')->placeholder('—'),
                TextColumn::make('booked_at')->label('報名時間')->dateTime('Y-m-d H:i', $tz),
                TextColumn::make('cancel_reason')->label('取消原因')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('attendees', 'waitlistEntry'))
            ->defaultSort('booked_at')
            ->filters([SelectFilter::make('status')->label('狀態')->options(self::STATUS_LABELS)])
            ->headerActions([
                Action::make('adminBook')
                    ->label('代報名／現場補報名')
                    ->schema([
                        Select::make('member_id')->label('預約會員')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->limit(20)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->name),
                        Select::make('member_user_id')->label('參加會員（每筆一人）')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->limit(20)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->name),
                    ])
                    ->action(function (array $data): void {
                        /** @var CalendarEvent $event */
                        $event = $this->getOwnerRecord();
                        $member = isset($data['member_id']) ? User::query()->find($data['member_id']) : null;
                        if ($member === null) {
                            Notification::make()->title('必須選擇正式會員')->danger()->send();

                            return;
                        }
                        $attendeeId = (int) ($data['member_user_id'] ?? 0);
                        $attendee = $attendeeId > 0
                            ? User::query()->whereKey($attendeeId)->where('is_active', true)->where('verified_status', 2)->first()
                            : null;
                        if ($attendee === null) {
                            Notification::make()->title('參加者必須是已驗證且啟用的正式會員')->danger()->send();

                            return;
                        }

                        try {
                            $group = app(BookingEngine::class)->book($event, new BookingRequest(
                                booker: $member,
                                contactName: $member->name,
                                contactEmail: $member->email !== null ? mb_strtolower(trim($member->email)) : null,
                                contactPhone: $member->phone_e164,
                                attendees: [['name' => $attendee->name, 'member_user_id' => $attendee->id]],
                                byAdmin: true,
                                actor: auth()->user(),
                            ));
                            Notification::make()->title($group->status === EventBookingGroup::STATUS_CONFIRMED ? '已報名' : '名額已滿，已列入候補')->success()->send();
                        } catch (ApiException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('cancelGroup')
                    ->label('取消報名')
                    ->visible(fn (EventBookingGroup $r): bool => $r->isActive())
                    ->requiresConfirmation()
                    ->schema([TextInput::make('reason')->label('原因（會通知聯絡人）')->maxLength(255)])
                    ->action(function (EventBookingGroup $record, array $data): void {
                        try {
                            app(BookingEngine::class)->cancelGroup($record, auth()->user(), true, $data['reason'] ?? null);
                            Notification::make()->title('已取消並依順序遞補')->success()->send();
                        } catch (ApiException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('moveWaitlist')
                    ->label('調整候補順位')
                    ->visible(fn (EventBookingGroup $r): bool => $r->status === EventBookingGroup::STATUS_WAITLISTED)
                    ->schema([
                        TextInput::make('position')->label('新順位（1＝第一位）')->numeric()->minValue(1)->required(),
                        TextInput::make('reason')->label('原因（必填，留稽核）')->required()->maxLength(255),
                    ])
                    ->action(function (EventBookingGroup $record, array $data): void {
                        try {
                            app(BookingEngine::class)->moveInWaitlist($record, (int) $data['position'], auth()->user(), (string) $data['reason']);
                            Notification::make()->title('已調整候補順位')->success()->send();
                        } catch (ApiException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
