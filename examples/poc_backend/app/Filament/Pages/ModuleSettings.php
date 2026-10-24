<?php

namespace App\Filament\Pages;

use App\Models\AuditEvent;
use App\Models\MembershipLevel;
use App\Support\Settings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use UnitEnum;

/**
 * S9 模組設定 — per-App content types (M14 share, M16 tags, M17 analytics, M19
 * search / bookmarks, M15 targets), calendar VIP levels (M09-D22), check-in GPS
 * defaults and retention (M11-D24/D26), statistics time zone (M17-D29).
 */
class ModuleSettings extends Page
{
    protected static string|UnitEnum|null $navigationGroup = '系統設定';

    protected static ?string $navigationLabel = 'S9 模組設定';

    protected static ?string $title = '系統設定 › S9 模組設定';

    protected static ?string $slug = 'module-settings';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.system-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    private const CSV_KEYS = [
        'content_search_types', 'content_bookmark_types', 'content_tag_types', 'content_share_types',
        'content_analytics_types', 'marketing_target_types', 'calendar_vip_level_codes',
    ];

    private const KEYS = [
        ...self::CSV_KEYS,
        'calendar_default_timezone', 'checkin_gps_required', 'checkin_gps_radius_m', 'checkin_retention_days', 'analytics_timezone',
    ];

    private const TYPE_LABELS = ['article' => '文章', 'event' => '活動', 'notice' => '公告', 'campaign' => '行銷活動', 'url' => '外部 HTTPS 網址'];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $values = array_intersect_key(app(Settings::class)->all(), array_flip(self::KEYS));
        foreach (self::CSV_KEYS as $key) {
            $values[$key] = Settings::csv((string) $values[$key]);
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $types = fn (string $key): array => array_intersect_key(self::TYPE_LABELS, array_flip(Settings::CSV_ALLOWED[$key]));
        $zones = array_combine(timezone_identifiers_list(), timezone_identifiers_list());

        return $schema->components([
            Tabs::make('modules')->tabs([
                Tab::make('內容類型')->schema([
                    CheckboxList::make('content_search_types')->label('M19 可搜尋')->options($types('content_search_types')),
                    CheckboxList::make('content_bookmark_types')->label('M19 可收藏')->options($types('content_bookmark_types')),
                    CheckboxList::make('content_tag_types')->label('M16 可使用標籤')->options($types('content_tag_types')),
                    CheckboxList::make('content_share_types')->label('M14 可分享（顯示分享與 QR Code）')->options($types('content_share_types')),
                    CheckboxList::make('content_analytics_types')->label('M17 統計點擊')->options($types('content_analytics_types')),
                    CheckboxList::make('marketing_target_types')->label('M15 行銷可導向')->options($types('marketing_target_types')),
                ]),
                Tab::make('日曆')->schema([
                    CheckboxList::make('calendar_vip_level_codes')->label('高級 VIP：可使用私人行程的會員等級')
                        ->options(fn (): array => MembershipLevel::query()->orderBy('rank')->pluck('name', 'code')->all()),
                    Select::make('calendar_default_timezone')->label('預設時區')->options($zones)->searchable()->required(),
                ]),
                Tab::make('簽到')->schema([
                    Toggle::make('checkin_gps_required')->label('地點 QR 預設需要 GPS（活動可覆寫）'),
                    TextInput::make('checkin_gps_radius_m')->label('預設允許距離（公尺）')->numeric()->integer()->minValue(10)->maxValue(5000)->required(),
                    TextInput::make('checkin_retention_days')->label('簽到紀錄保存天數（到期刪除簽到個資，稽核去識別）')->numeric()->integer()->minValue(1)->maxValue(3650)->required(),
                ]),
                Tab::make('統計')->schema([
                    Select::make('analytics_timezone')->label('統計固定時區')->options($zones)->searchable()->required(),
                ]),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = app(Settings::class);
        $before = $settings->all();
        $new = array_intersect_key($this->form->getState(), array_flip(self::KEYS));
        foreach (self::CSV_KEYS as $key) {
            $new[$key] = implode(',', array_values((array) ($new[$key] ?? [])));
        }

        try {
            $settings->put($new);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('設定值無效')->body($e->getMessage())->danger()->send();

            return;
        }

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
