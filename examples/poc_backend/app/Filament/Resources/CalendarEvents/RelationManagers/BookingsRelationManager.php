<?php

namespace App\Filament\Resources\CalendarEvents\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\EventBooking;
use App\Services\BookingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * S6 M08 booking list of one event. Rows are never deleted; an admin can cancel
 * a confirmed booking (kept and audited).
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = '預約名單';

    public function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->heading(fn (): string => '預約名單（'.CalendarEventResource::bookingSummary($this->getOwnerRecord()).'）')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('user.name')->label('會員')->searchable(),
                TextColumn::make('user.phone_e164')->label('手機')->placeholder('—'),
                TextColumn::make('user.email')->label('Email')->placeholder('—'),
                TextColumn::make('status')->label('狀態')
                    ->formatStateUsing(fn (string $state): string => $state === EventBooking::STATUS_CONFIRMED ? '已預約' : '【已取消】'),
                TextColumn::make('booked_at')->label('預約時間')->dateTime('Y-m-d H:i', $tz),
                TextColumn::make('cancelled_at')->label('取消時間')->dateTime('Y-m-d H:i', $tz)->placeholder('—'),
            ])
            ->defaultSort('booked_at')
            ->filters([
                SelectFilter::make('status')->label('狀態')
                    ->options([EventBooking::STATUS_CONFIRMED => '已預約', EventBooking::STATUS_CANCELLED => '已取消']),
            ])
            ->recordActions([
                Action::make('cancelBooking')
                    ->label('取消預約')
                    ->visible(fn (EventBooking $record): bool => $record->isConfirmed())
                    ->requiresConfirmation()
                    ->modalDescription('取消後名額釋出，紀錄保留。')
                    ->action(function (EventBooking $record): void {
                        app(BookingService::class)->cancel($record, auth()->user());

                        Notification::make()->title('已取消此預約')->success()->send();
                    }),
            ]);
    }
}
