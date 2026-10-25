<?php

namespace App\Filament\Resources\MemberVerifications;

use App\Filament\Resources\MemberVerifications\Pages\ListMemberVerifications;
use App\Models\MemberVerification;
use App\Services\Verification\VerificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S9 M03 granted verifications; revoke takes effect at once and keeps the record (D07).
 */
class MemberVerificationResource extends Resource
{
    protected static ?string $model = MemberVerification::class;

    protected static ?string $modelLabel = '會員認證';

    protected static ?string $pluralModelLabel = '會員認證';

    protected static ?string $slug = 'member-verifications';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('verifications.review') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone', 'UTC');

        return $table->modifyQueryUsing(fn ($query) => $query->with('user', 'type'))->columns([
            TextColumn::make('user.name')->label('會員')->searchable(),
            TextColumn::make('type.name')->label('認證'),
            TextColumn::make('state')->label('狀態')->state(fn (MemberVerification $r): string => ['valid' => '有效', 'expired' => '【已到期】', 'revoked' => '【已撤銷】'][$r->state()]),
            TextColumn::make('is_public')->label('徽章')->formatStateUsing(fn ($state): string => $state ? '公開' : '隱藏'),
            TextColumn::make('granted_at')->label('通過')->dateTime('Y-m-d', $tz),
            TextColumn::make('expires_at')->label('到期')->dateTime('Y-m-d', $tz)->placeholder('永久'),
            TextColumn::make('revoke_reason')->label('撤銷原因')->placeholder('—'),
        ])->defaultSort('granted_at', 'desc')->recordActions([
            Action::make('revoke')->label('撤銷')->color('danger')
                ->visible(fn (MemberVerification $r): bool => $r->revoked_at === null)
                ->requiresConfirmation()
                ->schema([TextInput::make('reason')->label('撤銷原因')->required()->maxLength(255)])
                ->action(function (MemberVerification $record, array $data): void {
                    app(VerificationService::class)->revoke($record, auth()->user(), (string) $data['reason']);
                    Notification::make()->title('已撤銷，立即生效')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMemberVerifications::route('/')];
    }
}
