<?php

namespace App\Filament\Resources\CalendarEvents;

use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\EventSeries;
use App\Services\Calendar\EventSeriesService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * S9 M09 series forms used by the calendar event admin (create a weekly/monthly
 * series; edit "this and future" or "all" not-yet-started occurrences).
 */
final class SeriesForms
{
    /**
     * @return list<mixed>
     */
    public static function createFields(): array
    {
        return [
            TextInput::make('title')->label('活動名稱')->required()->maxLength(255),
            Textarea::make('description')->label('說明')->rows(4)->maxLength(5000),
            Select::make('event_category_id')->label('活動類別')->options(fn (): array => EventCategory::query()->orderBy('sort')->pluck('name', 'id')->all()),
            TextInput::make('location')->label('地點（選填）')->maxLength(255),
            TextInput::make('floor')->label('樓層（選填）')->maxLength(255),
            TextInput::make('studio')->label('Studio（選填）')->maxLength(255),
            Select::make('audience')->label('對象')->options(CalendarEventResource::AUDIENCE_LABELS)->default(CalendarEvent::AUDIENCE_MEMBERS)->required(),
            Select::make('frequency')->label('重複')->options([EventSeries::WEEKLY => '每週', EventSeries::MONTHLY => '每月'])->required(),
            Select::make('timezone')->label('時區')->options(CalendarEventResource::TIMEZONES)->default('Asia/Taipei')->required(),
            DatePicker::make('starts_on')->label('第一場日期')->required(),
            DatePicker::make('until_on')->label('最後日期')->required()->after('starts_on'),
            TextInput::make('local_start_time')->label('開始時間（HH:MM）')->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->default('19:00'),
            TextInput::make('duration_minutes')->label('長度（分鐘）')->numeric()->minValue(1)->maxValue(10080)->required()->default(120),
            Toggle::make('booking_enabled')->label('開放預約'),
            TextInput::make('booking_capacity')->label('名額')->numeric()->minValue(1)->maxValue(100000),
            TextInput::make('booking_max_seats')->label('每筆報名最多人數')->numeric()->minValue(1)->maxValue(50)->default(1),
            TextInput::make('booking_close_offset_minutes')->label('預約截止：開始前 N 分鐘（選填）')->numeric()->minValue(0),
            TextInput::make('cancel_offset_minutes')->label('取消期限：開始前 N 分鐘（選填）')->numeric()->minValue(0),
            Toggle::make('checkin_enabled')->label('開放簽到'),
            Select::make('status')->label('建立後狀態')->options([CalendarEvent::STATUS_DRAFT => '草稿', CalendarEvent::STATUS_PUBLISHED => '發布'])->default(CalendarEvent::STATUS_DRAFT)->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): void
    {
        $template = array_intersect_key($data, array_flip(EventSeriesService::TEMPLATE_FIELDS));
        if (! ($template['booking_enabled'] ?? false)) {
            $template['booking_capacity'] = null;
        }

        try {
            $series = app(EventSeriesService::class)->create(
                array_intersect_key($data, array_flip(['frequency', 'timezone', 'local_start_time', 'duration_minutes', 'starts_on', 'until_on'])),
                $template,
                auth()->user(),
            );
            Notification::make()->title('已建立系列，共 '.$series->occurrences()->count().' 個場次')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('無法建立系列')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
        }
    }

    /**
     * @return list<mixed>
     */
    public static function batchFields(): array
    {
        return [
            Select::make('scope')->label('套用範圍')->options(['future' => '此次及未來', 'all' => '全部'])->required()->default('future')
                ->helperText('已開始／結束的場次與已單次修改的場次不會被覆蓋（S9-L01）。'),
            TextInput::make('title')->label('活動名稱')->required()->maxLength(255),
            Textarea::make('description')->label('說明')->rows(4)->maxLength(5000),
            TextInput::make('location')->label('地點')->maxLength(255),
            TextInput::make('floor')->label('樓層')->maxLength(255),
            TextInput::make('studio')->label('Studio')->maxLength(255),
            TextInput::make('local_start_time')->label('開始時間（HH:MM，空白＝不變）')->regex('/^([01]\d|2[0-3]):[0-5]\d$/'),
            TextInput::make('duration_minutes')->label('長度（分鐘，空白＝不變）')->numeric()->minValue(1)->maxValue(10080),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fill(CalendarEvent $record): array
    {
        return ['scope' => 'future'] + $record->only(['title', 'description', 'location', 'floor', 'studio']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function applyBatch(CalendarEvent $record, array $data): void
    {
        $changes = array_intersect_key($data, array_flip(['title', 'description', 'location', 'floor', 'studio']));
        foreach (['local_start_time', 'duration_minutes'] as $key) {
            if (filled($data[$key] ?? null)) {
                $changes[$key] = $data[$key];
            }
        }

        try {
            $result = app(EventSeriesService::class)->update($record, (string) $data['scope'], $changes, auth()->user());
        } catch (ValidationException $e) {
            Notification::make()->title('無法修改')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

            return;
        }

        Notification::make()
            ->title('已更新 '.count($result['updated']).' 個場次，通知 '.$result['notified'].' 組報名')
            ->body(implode(' ', array_filter([
                $result['skipped_started'] !== [] ? '略過已開始場次 '.count($result['skipped_started']).' 個。' : null,
                $result['skipped_exceptions'] !== [] ? '略過單次例外 '.count($result['skipped_exceptions']).' 個。' : null,
                $result['fixed_deadlines'] !== [] ? '注意：場次 #'.implode(', #', $result['fixed_deadlines']).' 有固定的預約／取消期限，已保留，請檢查（S9-L02）。' : null,
            ])))
            ->success()->persistent()->send();
    }
}
