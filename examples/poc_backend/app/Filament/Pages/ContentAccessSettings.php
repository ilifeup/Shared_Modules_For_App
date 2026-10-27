<?php

namespace App\Filament\Pages;

use App\Services\ContentAccess\ContentAccessRuleManager;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class ContentAccessSettings extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?string $navigationLabel = 'App 頁面／列表／主題權限';

    protected static ?string $title = 'App 三層瀏覽權限';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.content-access-settings';

    public string $scope = 'page';

    public string $resourceCode = '';

    public string $decision = 'allow';

    public string $audienceType = 'guest';

    public string $audienceId = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(Filament::getPanel('admin')) && (auth()->user()?->can('settings.manage') ?? false);
    }

    public function rules(): array
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('app_content_access_rules')
            ->orderBy('scope')->orderBy('resource_code')->orderBy('audience')
            ->get(['scope', 'resource_code', 'audience', 'allowed'])
            ->toArray();
    }

    public function saveRule(ContentAccessRuleManager $manager): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate([
            'scope' => 'required|in:page,list,topic',
            'resourceCode' => 'required|string|max:120',
            'audienceType' => 'required|in:guest,register_member,verified_member,level,user',
            'audienceId' => 'required_if:audienceType,level,user|nullable|integer|min:1',
            'decision' => 'required|in:allow,deny',
        ]);

        $audience = in_array($this->audienceType, ['level', 'user'], true)
            ? $this->audienceType.':'.$this->audienceId
            : $this->audienceType;
        $manager->save(auth()->user(), $this->scope, trim($this->resourceCode), $audience, $this->decision === 'allow');
        Notification::make()->title('瀏覽權限已儲存')->success()->send();
    }

    public function removeRule(ContentAccessRuleManager $manager, string $scope, string $code, string $audience): void
    {
        abort_unless(static::canAccess(), 403);
        $manager->remove(auth()->user(), $scope, $code, $audience);
        Notification::make()->title('瀏覽權限已刪除')->success()->send();
    }
}
