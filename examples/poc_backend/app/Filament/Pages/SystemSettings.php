<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Support\Settings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * 系統設定 — every value is stored in app_settings and enforced by the backend
 * (Sanctum token callback, EnforceAdminIdle, AuthController, PhoneOtpService).
 */
class SystemSettings extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?string $navigationLabel = '登入與簡訊設定';

    protected static ?string $title = '系統設定 › 登入與簡訊設定';

    protected static ?string $slug = 'system-settings';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.system-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Keys edited on this page (the verification policy has its own page). */
    private const KEYS = [
        'member_long_login', 'member_idle_minutes', 'admin_idle_minutes',
        'sms_test_mode', 'sms_provider', 'sms_global_hourly_limit', 'sms_phone_hourly_limit',
        'sms_resend_cooldown_minutes', 'otp_ttl_minutes', 'otp_max_attempts',
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(array_intersect_key(app(Settings::class)->all(), array_flip(self::KEYS)));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('settings')->tabs([
                    Tab::make('登入期限')->schema([
                        Toggle::make('member_long_login')
                            ->label('會員長期保持登入（開啟＝會員 Token 不因閒置而失效）'),
                        TextInput::make('member_idle_minutes')
                            ->label('會員 Token 期限：閒置幾分鐘後需重新登入（僅在關閉長期登入時生效）')
                            ->numeric()->integer()->minValue(5)->required(),
                        TextInput::make('admin_idle_minutes')
                            ->label('Admin 閒置登出期限（分鐘，預設 10080＝7 天）')
                            ->numeric()->integer()->minValue(5)->required(),
                    ]),
                    Tab::make('SMS OTP')->schema([
                        Toggle::make('sms_test_mode')
                            ->label('測試模式（開啟＝不發送真實簡訊，不產生費用）'),
                        Select::make('sms_provider')->label('SMS 業者')
                            ->options([
                                'fake' => '測試用（不發送）',
                                'mitake' => '三竹資訊 Mitake',
                                'every8d' => 'EVERY8D 互動資通',
                                'smsgo' => '簡訊購 SMSGO',
                            ])
                            ->helperText('業者帳號密碼只設定在伺服器環境變數，不在此頁輸入。')
                            ->required(),
                        TextInput::make('sms_global_hourly_limit')->label('每小時 SMS 發送總額')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('sms_phone_hourly_limit')->label('每門號每小時發送上限')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('sms_resend_cooldown_minutes')->label('每門號重發冷卻（分鐘）')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('otp_ttl_minutes')->label('OTP 有效時間（分鐘）')
                            ->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('otp_max_attempts')->label('OTP 錯誤次數上限')
                            ->numeric()->integer()->minValue(1)->required(),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(Settings::class);
        $before = $settings->all();
        $new = array_intersect_key($this->form->getState(), array_flip(self::KEYS));
        $settings->put($new);
        $after = $settings->all();

        $changes = [];
        foreach (array_keys($new) as $key) {
            if ($before[$key] !== $after[$key]) {
                $changes[$key] = ['old' => $before[$key], 'new' => $after[$key]];
            }
        }
        AuditEvent::record('settings.updated', auth()->id(), null, ['changes' => $changes]);

        Notification::make()->title('設定已儲存並立即生效')->success()->send();
    }
}
