<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Members of this App only (the administrator is excluded). Members register
 * themselves in the App, so there is no "create" page and no password field.
 */
class MemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = '會員';

    protected static ?string $pluralModelLabel = '會員管理';

    protected static ?string $slug = 'members';

    protected static ?int $navigationSort = 1;

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', User::ADMIN_ROLE));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('姓名')->required()->maxLength(255),
            TextInput::make('display_name')->label('顯示名稱')->maxLength(80),
            TextInput::make('email')->label('Email')->email()->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('phone_e164')->label('手機（E.164）')->maxLength(20)
                ->unique(ignoreRecord: true)
                ->regex('/^\+8869\d{8}$/')
                ->helperText('格式 +886912345678'),
            Toggle::make('is_active')->label('啟用（關閉後無法登入，現有登入也會失效）'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('display_name')->label('顯示名稱')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('phone_e164')->label('手機')->searchable(),
                TextColumn::make('email_verified_at')->label('Email 驗證')
                    ->state(fn (User $record): string => $record->email === null ? '—' : ($record->email_verified_at ? '✔ 已驗證' : '✖ 未驗證')),
                TextColumn::make('phone_verified_at')->label('手機驗證')
                    ->state(fn (User $record): string => $record->phone_e164 === null ? '—' : ($record->phone_verified_at ? '✔ 已驗證' : '✖ 未驗證')),
                TextColumn::make('is_active')->label('狀態')
                    ->state(fn (User $record): string => $record->is_active ? '啟用' : '【停用】'),
                TextColumn::make('created_at')->label('註冊時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                Action::make('revokeTokens')
                    ->label('撤銷全部登入')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->tokens()->delete();

                        Notification::make()->title('已撤銷此會員的全部登入')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
