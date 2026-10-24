<?php

namespace App\Filament\Resources\MarketingCampaigns;

use App\Filament\Pages\LinkAnalyticsReport;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\MarketingCampaigns\Pages\CreateMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\EditMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\ListMarketingCampaigns;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingService;
use App\Support\Api\ApiException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * S9 M15 行銷活動 (marketing.manage, D04): material, M14 / HTTPS target, placements,
 * schedule and priority; preview, publish (server validation), disable, duplicate,
 * confirmed delete; single-record operations only (D24); results in M17 (D22).
 */
class MarketingCampaignResource extends Resource
{
    protected static ?string $model = MarketingCampaign::class;

    protected static ?string $modelLabel = '行銷活動';

    protected static ?string $pluralModelLabel = '行銷活動';

    protected static ?string $slug = 'marketing-campaigns';

    protected static ?int $navigationSort = 9;

    public const STATES = ['draft' => '草稿', 'scheduled' => '已排程', 'active' => '進行中', 'ended' => '已結束', 'disabled' => '【停用】'];

    public const TARGETS = ['article' => '文章', 'event' => '活動', 'notice' => '公告', 'url' => '外部 HTTPS 網址'];

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('marketing.manage') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // through the confirmed delete action (audit)
    }

    public static function form(Schema $schema): Schema
    {
        $tz = CalendarEventResource::displayTimezone();

        return $schema->components([
            TextInput::make('title')->label('標題')->required()->maxLength(120),
            Textarea::make('summary')->label('摘要')->rows(3)->maxLength(500),
            FileUpload::make('image_path')->label('圖片（建議 16:9，例如 1200×675）')->image()->disk('local')->directory('marketing')->visibility('private')->maxSize(5120),
            TextInput::make('button_text')->label('按鈕文字')->required()->maxLength(30)->default('查看'),
            Select::make('target_type')->label('點擊導向')->options(self::TARGETS)->required()->live(),
            TextInput::make('target_id')->label('內容 ID')->numeric()->integer()->minValue(1)
                ->visible(fn (Get $get): bool => in_array($get('target_type'), ['article', 'event', 'notice'], true))
                ->required(fn (Get $get): bool => in_array($get('target_type'), ['article', 'event', 'notice'], true)),
            TextInput::make('target_url')->label('外部網址（https://）')->url()->maxLength(2048)
                ->visible(fn (Get $get): bool => $get('target_type') === 'url')->required(fn (Get $get): bool => $get('target_type') === 'url'),
            Select::make('placements')->label('展示區塊')->relationship('placements', 'name')->multiple()->preload()->required(),
            TextInput::make('priority')->label('優先順序（數字大者先顯示）')->numeric()->integer()->default(0),
            DateTimePicker::make('starts_at')->label('開始時間')->seconds(false)->timezone($tz)->required(),
            DateTimePicker::make('ends_at')->label('結束時間')->seconds(false)->timezone($tz)->after('starts_at')->required(),
            TextEntry::make('state_display')->label('目前狀態')->state(fn (?MarketingCampaign $record): string => $record ? self::STATES[$record->state()] : '草稿'),
            TextEntry::make('last_error')->label('最近展示錯誤')->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = CalendarEventResource::displayTimezone();

        return $table
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('title')->label('標題')->searchable()->limit(30),
                TextColumn::make('state')->label('狀態')->state(fn (MarketingCampaign $r): string => self::STATES[$r->state()]),
                TextColumn::make('placements.name')->label('區塊')->badge(),
                TextColumn::make('priority')->label('優先')->sortable(),
                TextColumn::make('starts_at')->label('開始')->dateTime('Y-m-d H:i', $tz)->sortable(),
                TextColumn::make('ends_at')->label('結束')->dateTime('Y-m-d H:i', $tz),
                TextColumn::make('last_error')->label('展示錯誤')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->label('狀態')->options(['draft' => '草稿', 'published' => '已發布', 'disabled' => '停用'])])
            ->recordActions([
                EditAction::make(),
                Action::make('preview')->label('預覽')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (MarketingCampaign $record) => view('filament.marketing-preview', [
                        'campaign' => $record,
                        'problems' => app(MarketingService::class)->problems($record),
                        'item' => app(MarketingService::class)->displayItem($record, auth()->user()),
                    ])),
                Action::make('publish')->label('發布')
                    ->visible(fn (MarketingCampaign $r): bool => $r->status !== MarketingCampaign::PUBLISHED)
                    ->requiresConfirmation()
                    ->action(function (MarketingCampaign $record): void {
                        try {
                            app(MarketingService::class)->publish($record, auth()->user());
                            Notification::make()->title('已發布，將依排程顯示')->success()->send();
                        } catch (ApiException $e) {
                            Notification::make()->title('無法發布')->body(implode("\n", $e->fields['campaign'] ?? [$e->getMessage()]))->danger()->persistent()->send();
                        }
                    }),
                Action::make('disable')->label('停用')
                    ->visible(fn (MarketingCampaign $r): bool => $r->status === MarketingCampaign::PUBLISHED)
                    ->requiresConfirmation()
                    ->action(function (MarketingCampaign $record): void {
                        app(MarketingService::class)->disable($record, auth()->user());
                        Notification::make()->title('已停用，之後不再展示')->success()->send();
                    }),
                Action::make('duplicate')->label('複製為草稿')
                    ->action(function (MarketingCampaign $record): void {
                        $copy = app(MarketingService::class)->duplicate($record, auth()->user());
                        Notification::make()->title('已建立草稿 #'.$copy->id.'，請重新設定排程')->success()->send();
                    }),
                Action::make('analytics')->label('成效（M17）')
                    ->visible(fn (): bool => auth()->user()?->can('analytics.view') ?? false)
                    ->url(fn (MarketingCampaign $record): string => LinkAnalyticsReport::getUrl(['type' => 'campaign', 'content' => $record->id])),
                Action::make('deleteCampaign')->label('刪除')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('刪除後無法復原；M17 保存期限內的去識別化統計會保留。')
                    ->action(function (MarketingCampaign $record): void {
                        app(MarketingService::class)->delete($record, auth()->user());
                        Notification::make()->title('已刪除')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingCampaigns::route('/'),
            'create' => CreateMarketingCampaign::route('/create'),
            'edit' => EditMarketingCampaign::route('/{record}/edit'),
        ];
    }
}
