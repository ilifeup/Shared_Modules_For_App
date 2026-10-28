<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $title = '報名與候補名單';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('報名 ID')->sortable(),
            TextColumn::make('user.name')->label('會員姓名')->searchable(),
            TextColumn::make('user.email')->label('會員 Email')->placeholder('—'),
            TextColumn::make('booker.name')->label('報名操作人')->placeholder('—'),
            TextColumn::make('status')->label('狀態')->formatStateUsing(fn (string $state): string => match ($state) {
                'pending_payment' => '待付款',
                'confirmed' => '已確認',
                'waitlisted' => '候補中',
                'cancelled' => '已取消',
                default => $state,
            }),
            TextColumn::make('invoice.invoice_code')->label('整期帳單編號')->placeholder('—'),
            TextColumn::make('invoice.invoice_amount')->label('應收金額（NT$）')->money('TWD')->placeholder('—'),
            TextColumn::make('invoice.payment_due_at')->label('付款期限')->dateTime('Y-m-d H:i', timezone: CalendarEventResource::displayTimezone())->placeholder('—'),
            TextColumn::make('invoice.invoice_status')->label('帳單狀態')
                ->formatStateUsing(fn ($state): string => $state === null ? '無帳單' : match ((int) $state) {
                    0 => '未付款',
                    1 => '已付款',
                    2 => '已取消',
                    3 => '已逾期',
                    4 => '退款中',
                    5 => '已退款',
                    default => '—',
                })->placeholder('—'),
            TextColumn::make('waitlist_sequence')->label('候補順位')->sortable()->placeholder('—'),
            TextColumn::make('fee_confirmed_at')->label('收款確認時間')->dateTime('Y-m-d H:i', timezone: CalendarEventResource::displayTimezone())->placeholder('—'),
        ]);
    }
}
