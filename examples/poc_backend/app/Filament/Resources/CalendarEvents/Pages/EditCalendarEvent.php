<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Services\Booking\BookingEngine;
use App\Services\Content\TagService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing one occurrence = scope "single": a series occurrence becomes an exception
 * (never overwritten by later batch edits, S9-L01). Time or venue changes keep the
 * bookings, notify booked and waitlisted contacts (M08-D11) and flag fixed deadlines
 * for review (S9-L02).
 */
class EditCalendarEvent extends EditRecord
{
    protected static string $resource = CalendarEventResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['tag_ids'] = app(TagService::class)->tagsOf('event', (int) $this->record->id, false)->pluck('id')->all();

        return $data;
    }

    protected function beforeSave(): void
    {
        /** @var CalendarEvent $event */
        $event = $this->record;
        $this->before = [
            'starts_at' => $event->getOriginal('starts_at'),
            'ends_at' => $event->getOriginal('ends_at'),
            'location' => $event->getOriginal('location'),
            'floor' => $event->getOriginal('floor'),
            'studio' => $event->getOriginal('studio'),
        ];
    }

    protected function afterSave(): void
    {
        CalendarEventResource::saveTags($this->record, $this->form->getRawState());

        /** @var CalendarEvent $event */
        $event = $this->record->refresh();
        $timeChanged = ! $event->starts_at->equalTo($this->before['starts_at']) || ! $event->ends_at->equalTo($this->before['ends_at']);
        $placeChanged = $event->location !== $this->before['location'] || $event->floor !== $this->before['floor'] || $event->studio !== $this->before['studio'];

        if ($event->event_series_id !== null && ! $event->is_series_exception) {
            $event->forceFill(['is_series_exception' => true])->saveQuietly();
        }
        if (! $timeChanged && ! $placeChanged) {
            return;
        }

        $tz = $event->timezone ?? 'Asia/Taipei';
        $what = implode('、', array_filter([$timeChanged ? '時間' : null, $placeChanged ? '地點' : null]));
        $notified = app(BookingEngine::class)->notifyChange($event, '活動異動：'.$event->title,
            '「'.$event->title.'」的'.$what.'已變更：'.$event->starts_at->timezone($tz)->format('Y-m-d H:i')
            .($event->location || $event->floor || $event->studio ? '，'.implode(' ', array_filter([$event->location, $event->floor, $event->studio])) : '').'。您的報名／候補維持不變。');

        $fixed = $timeChanged ? $event->fixedDeadlines() : [];
        AuditEvent::record($timeChanged ? 'event.rescheduled' : 'event.venue_changed', auth()->id(), $event, [
            'scope' => 'single', 'notified' => $notified, 'fixed_deadline_kept' => $fixed,
        ]);

        $notice = Notification::make()->title('已通知 '.$notified.' 組報名／候補');
        if ($fixed !== []) {
            $notice->body('此場次有固定的預約或取消期限，已保留原設定，請檢查是否需要調整（S9-L02）。')->warning()->persistent();
        } else {
            $notice->success();
        }
        $notice->send();
    }
}
