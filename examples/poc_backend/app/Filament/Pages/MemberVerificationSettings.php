<?php

namespace App\Filament\Pages;

use App\Support\Settings;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class MemberVerificationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationLabel = '會員驗證政策';

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $title = '會員驗證政策';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.member-verification-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['verification_policy' => app(Settings::class)->string('verification_policy')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('verification_policy')
                ->label('新會員的驗證要求')
                ->options([
                    Settings::POLICY_EMAIL => 'Email 驗證（上架前）',
                    Settings::POLICY_PHONE => '手機驗證（上架後）',
                ])
                ->required()
                ->helperText('V01-A：從 Email 改為手機時，已完成 Email 驗證的既有會員保留資格；切換後的新會員須完成手機驗證。'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        app(Settings::class)->put(['verification_policy' => $state['verification_policy']]);

        Notification::make()->title('會員驗證政策已更新')->success()->send();
    }
}
