<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\RelationManagers\LevelAssignmentsRelationManager;
use App\Models\MemberLevelAssignment;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Notifications\MemberNotification;
use App\Services\MemberNotifier;
use App\Services\MembershipService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Members of this App only (the administrator is excluded). Members register
 * themselves in the App, so there is no "create" page and no password field.
 * Changing an email or phone here clears its verification (never auto-verified).
 */
class MemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = '會員';

    protected static ?string $pluralModelLabel = '會員管理';

    protected static ?string $slug = 'members';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('members.manage') ?? false;
    }

    /**
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('levelAssignments.level')
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', User::ADMIN_ROLE));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('姓名')->required()->maxLength(255),
            TextInput::make('display_name')->label('顯示名稱')->maxLength(80),
            TextInput::make('email')->label('Email')->email()->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('修改後此 Email 會變成「未驗證」，需由會員重新驗證。'),
            TextInput::make('phone_e164')->label('手機（E.164）')->maxLength(20)
                ->unique(ignoreRecord: true)
                ->regex('/^\+8869\d{8}$/')
                ->helperText('格式 +886912345678。修改後此手機會變成「未驗證」。'),
            Toggle::make('attachment_upload_enabled')->label('允許附件上傳（管理員手動開通）')
                ->helperText('與 App 付款及會員等級無關；實名驗證、期限與配額仍由後端檢查。'),
            DateTimePicker::make('attachment_upload_expires_at')->label('附件上傳權限到期（留空＝無期限）'),
            TextInput::make('attachment_weekly_upload_count')->label('每週附件上傳次數覆寫（空白＝全站預設 1 次）')->numeric()->minValue(0),
            TextInput::make('attachment_weekly_upload_bytes')->label('每週上傳容量覆寫（Bytes；空白＝全站預設 5242880）')->numeric()->minValue(0),
            Textarea::make('admin_identity_note')->label('身份備註（限管理員看）')->maxLength(1000)->rows(3),
            Toggle::make('is_active')->label('啟用（關閉後無法登入，現有登入也會失效）'),
        ]);
    }

    public static function currentLevel(User $record): string
    {
        $now = now();
        $active = $record->levelAssignments
            ->filter(fn (MemberLevelAssignment $a): bool => $a->isActiveAt($now))
            ->sortByDesc(fn (MemberLevelAssignment $a): int => $a->level->rank)
            ->first();

        if ($active === null) {
            return MembershipLevel::default()->name;
        }

        return $active->level->name.($active->ends_at ? '（至 '.$active->ends_at->format('Y-m-d').'）' : '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('name')->label('姓名')->searchable(),
                TextColumn::make('display_name')->label('顯示名稱')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('phone_e164')->label('手機')->searchable(),
                TextColumn::make('email_verified_at')->label('Email 驗證')
                    ->state(fn (User $record): string => $record->email === null ? '— 未填' : ($record->email_verified_at ? '✔ 已驗證' : '✖ 未驗證')),
                TextColumn::make('phone_verified_at')->label('手機驗證')
                    ->state(fn (User $record): string => $record->phone_e164 === null ? '— 未填' : ($record->phone_verified_at ? '✔ 已驗證' : '✖ 未驗證')),
                TextColumn::make('level')->label('會員等級')
                    ->state(fn (User $record): string => static::currentLevel($record)),
                TextColumn::make('is_active')->label('狀態')
                    ->state(fn (User $record): string => $record->is_active ? '啟用' : '【停用】'),
                TextColumn::make('created_at')->label('註冊時間')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TernaryFilter::make('email_verified_at')->label('Email 驗證')->nullable()
                    ->trueLabel('已驗證')->falseLabel('未驗證'),
                TernaryFilter::make('phone_verified_at')->label('手機驗證')->nullable()
                    ->trueLabel('已驗證')->falseLabel('未驗證'),
                TernaryFilter::make('is_active')->label('帳號狀態')
                    ->trueLabel('啟用')->falseLabel('停用'),
                Filter::make('vip')->label('有效 VIP')
                    ->query(fn (Builder $query) => $query->whereHas('levelAssignments', fn (Builder $a) => $a
                        ->activeAt(now())
                        ->whereHas('level', fn (Builder $l) => $l->where('code', MembershipLevel::VIP)))),
                Filter::make('incomplete')->label('資料未完整（缺 Email 或手機）')
                    ->query(fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('email')->orWhereNull('phone_e164'))),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('assignLevel')
                    ->label('指派等級')
                    ->schema([
                        Select::make('membership_level_id')->label('等級')->required()
                            ->options(fn (): array => MembershipLevel::query()->where('is_default', false)->orderBy('rank')->pluck('name', 'id')->all()),
                        DateTimePicker::make('starts_at')->label('開始時間')->required()->default(now()),
                        DateTimePicker::make('ends_at')->label('結束時間（空白＝不限期）')->after('starts_at'),
                        TextInput::make('reason')->label('原因')->maxLength(255),
                    ])
                    ->action(function (User $record, array $data): void {
                        app(MembershipService::class)->assign(
                            $record,
                            MembershipLevel::query()->findOrFail($data['membership_level_id']),
                            Carbon::parse($data['starts_at']),
                            filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null,
                            $data['reason'] ?? null,
                            auth()->user(),
                        );

                        Notification::make()->title('已指派會員等級')->success()->send();
                    }),
                Action::make('sendNotification')
                    ->label('傳送站內通知')
                    ->visible(fn (): bool => auth()->user()?->can('notifications.send') ?? false)
                    ->schema(static::notificationSchema())
                    ->action(function (User $record, array $data): void {
                        static::notify([$record], $data);
                    }),
                Action::make('revokeTokens')
                    ->label('撤銷全部登入')
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->tokens()->delete();

                        Notification::make()->title('已撤銷此會員的全部登入')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('sendNotificationBulk')
                    ->label('傳送站內通知給選取的會員')
                    ->visible(fn (): bool => auth()->user()?->can('notifications.send') ?? false)
                    ->schema(static::notificationSchema())
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        static::notify($records, $data);
                    }),
            ]);
    }

    /**
     * S4 M04: in-app notification form (no email / SMS / push).
     *
     * @return array<int, mixed>
     */
    public static function notificationSchema(): array
    {
        return [
            TextInput::make('title')->label('標題')->required()->maxLength(120),
            Textarea::make('body')->label('內容')->required()->rows(5)->maxLength(2000),
            Select::make('type')->label('類型')->required()->default('system')
                ->options(['system' => '系統', 'account' => '帳號', 'membership' => '會員等級', 'notice' => '公告']),
            TextInput::make('notice_id')->label('導向公告編號（選填）')->numeric()->minValue(1),
        ];
    }

    /**
     * @param  iterable<User>  $members
     * @param  array<string, mixed>  $data
     */
    public static function notify(iterable $members, array $data): void
    {
        abort_unless(auth()->user()?->can('notifications.send'), 403);

        $noticeId = filled($data['notice_id'] ?? null) ? (string) (int) $data['notice_id'] : null;
        $count = app(MemberNotifier::class)->send(
            $members,
            (string) $data['title'],
            (string) $data['body'],
            in_array($data['type'] ?? null, MemberNotification::TYPES, true) ? $data['type'] : 'system',
            $noticeId !== null ? 'notice' : null,
            $noticeId,
            auth()->user(),
        );

        Notification::make()->title("已傳送站內通知給 {$count} 位會員")->success()->send();
    }

    public static function getRelations(): array
    {
        return [
            LevelAssignmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
