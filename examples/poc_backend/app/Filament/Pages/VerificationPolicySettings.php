<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * 系統設定 → 會員驗證設定 (S3 A02). Switching takes effect immediately for every
 * request (no redeploy) and is recorded in the audit log with old and new values.
 */
class VerificationPolicySettings extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?string $navigationLabel = '會員驗證設定';

    protected static ?string $title = '系統設定 › 會員驗證設定';

    protected static ?string $slug = 'verification-policy';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.verification-policy';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function currentPolicy(): string
    {
        return app(Settings::class)->string('verification_policy');
    }

    /**
     * Real SMS delivery is possible only with test mode off, a real provider and its credentials.
     */
    public function smsAvailable(): bool
    {
        $settings = app(Settings::class);
        $provider = $settings->string('sms_provider');

        if ($settings->bool('sms_test_mode') || $provider === 'fake') {
            return false;
        }

        $config = (array) config("services.sms.{$provider}", []);

        return collect($config)->except(['endpoint', 'site'])->filter(fn ($value) => filled($value))->count() >= 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('useEmailRequired')
                ->label('切換為「Email 驗證必要」')
                ->visible(fn (): bool => $this->currentPolicy() !== Settings::POLICY_EMAIL)
                ->requiresConfirmation()
                ->modalHeading('切換為 Email 驗證必要？')
                ->modalDescription('已登入但尚未完成 Email 驗證的會員，下一次操作起將被要求先驗證 Email。')
                ->action(fn () => $this->switchTo(Settings::POLICY_EMAIL)),
            Action::make('usePhoneRequired')
                ->label('切換為「手機驗證必要」')
                ->visible(fn (): bool => $this->currentPolicy() !== Settings::POLICY_PHONE)
                ->requiresConfirmation()
                ->modalHeading('切換為手機驗證必要？')
                ->modalDescription(fn (): string => $this->smsAvailable()
                    ? '已登入但尚未完成手機驗證的會員，下一次操作起將被要求先完成手機 OTP 驗證。'
                    : '【警告】目前沒有可用的真實簡訊（測試模式或業者未設定）。切換後會員將無法收到驗證碼，可能無法使用 App。')
                ->schema(fn (): array => $this->smsAvailable() ? [] : [
                    Checkbox::make('acknowledge')
                        ->label('我了解目前沒有真實簡訊，仍要切換')
                        ->accepted(),
                ])
                ->action(fn () => $this->switchTo(Settings::POLICY_PHONE)),
        ];
    }

    public function switchTo(string $policy): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(Settings::class);
        $old = $settings->string('verification_policy');

        if ($old === $policy) {
            return;
        }

        $settings->put(['verification_policy' => $policy]);
        AuditEvent::record('member.verification_policy_changed', auth()->id(), null, ['old' => $old, 'new' => $policy]);

        Notification::make()->title('會員驗證政策已切換，立即生效')->success()->send();
    }
}
