<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
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

    public ?string $revocationMemberId = null;

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
                    ? '依 V01-A，已通過 Email 驗證的既有會員保留資格；新會員須完成手機 OTP 驗證。'
                    : '【警告】目前沒有可用的真實簡訊（測試模式或業者未設定）。既有 Email 已驗證會員依 V01-A 保留資格，但新會員可能無法完成手機驗證。')
                ->schema(fn (): array => $this->smsAvailable() ? [] : [
                    Checkbox::make('acknowledge')
                        ->label('我了解目前沒有真實簡訊，仍要切換')
                        ->accepted(),
                ])
                ->action(fn () => $this->switchTo(Settings::POLICY_PHONE)),
        ];
    }

    public function revokeMemberById(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->validate([
            'revocationMemberId' => ['required', 'integer', 'min:1', 'exists:users,id'],
        ]);

        $this->revokeGrandfathering((int) $data['revocationMemberId']);
        $this->revocationMemberId = null;
        Notification::make()->title('撤銷操作已處理')->success()->send();
    }

    /**
     * Explicit administrator action: revocation is permanent across policy switches.
     * Completing phone OTP remains a separate way to satisfy phone policy.
     */
    public function revokeGrandfathering(int $memberId): void
    {
        abort_unless(static::canAccess(), 403);

        DB::transaction(function () use ($memberId): void {
            $member = User::query()->lockForUpdate()->findOrFail($memberId);
            if ($member->verification_grandfathered_at === null || $member->verification_grandfather_revoked_at !== null) {
                return;
            }

            $member->forceFill(['verification_grandfather_revoked_at' => now()])->save();
            AuditEvent::record('member.verification_grandfather_revoked', auth()->id(), $member, [
                'verification_grandfathered_at' => $member->verification_grandfathered_at->toIso8601String(),
            ]);
        });
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
