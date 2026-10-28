<?php

namespace App\Filament\Resources\CourseTerms\RelationManagers;

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
            TextColumn::make('id')->label('報名 ID'),
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
                TextColumn::make('waitlist_sequence')->label('候補順位')->placeholder('—'),
                TextColumn::make('fee_confirmed_at')->label('收款確認時間')->dateTime('Y-m-d H:i')->placeholder('—'),
        ]);
    }
}
