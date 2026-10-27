<?php

namespace App\Filament\Pages;

use App\Support\Settings;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class AppLegalNotices extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?string $navigationLabel = 'App 聲明與同意書';

    protected static ?string $title = 'App 聲明與同意書';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.app-legal-notices';

    public string $guestDisclaimer = '';

    public string $registrationPrivacy = '';

    public string $verificationPrivacy = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(Settings::class);
        $this->guestDisclaimer = $settings->string('guest_disclaimer_text');
        $this->registrationPrivacy = $settings->string('registration_privacy_text');
        $this->verificationPrivacy = $settings->string('verification_privacy_text');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate([
            'guestDisclaimer' => 'required|string|max:20000',
            'registrationPrivacy' => 'required|string|max:20000',
            'verificationPrivacy' => 'required|string|max:20000',
        ]);

        app(Settings::class)->put([
            'guest_disclaimer_text' => $this->guestDisclaimer,
            'registration_privacy_text' => $this->registrationPrivacy,
            'verification_privacy_text' => $this->verificationPrivacy,
        ]);

        Notification::make()->title('App 聲明文字已儲存')->success()->send();
    }
}
