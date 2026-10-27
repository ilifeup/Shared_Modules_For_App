<?php

namespace App\Services\Calendar;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventSeries;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * S9 M09 weekly / monthly series (D03, D09, D19) on top of calendar_events:
 * every occurrence is a real calendar_events row with a stable id (S9-L05 A).
 *
 * Scopes: single (this occurrence; becomes an exception), future (this and later),
 * all. Batch scopes never touch occurrences that already started and never
 * overwrite single exceptions (S9-L01 A). Rescheduling keeps confirmed and
 * waitlisted bookings; relative deadlines follow, fixed ones stay and are flagged
 * (S9-L02 A); booked and waitlisted contacts are notified (M08-D11).
 */
class EventSeriesService
{
    public const SCOPES = ['single', 'future', 'all'];

    /** Fields copied from the template to each occurrence. */
    public const TEMPLATE_FIELDS = [
        'title', 'description', 'location', 'floor', 'studio', 'audience', 'event_category_id', 'status',
        'booking_enabled', 'booking_capacity', 'booking_max_seats', 'waitlist_enabled',
        'booking_close_offset_minutes', 'cancel_offset_minutes',
        'checkin_enabled', 'checkout_enabled', 'checkin_open_before_minutes', 'checkin_close_after_end_minutes',
        'checkin_gps_required', 'checkin_gps_radius_m',
    ];

    /** Fields a batch (future / all) change may set. */
    public const BATCH_FIELDS = [
        'title', 'description', 'location', 'floor', 'studio', 'audience', 'event_category_id',
        'booking_enabled', 'booking_capacity', 'booking_max_seats', 'waitlist_enabled',
        'booking_close_offset_minutes', 'cancel_offset_minutes',
        'checkin_enabled', 'checkout_enabled', 'checkin_open_before_minutes', 'checkin_close_after_end_minutes',
        'checkin_gps_required', 'checkin_gps_radius_m',
    ];

    public function __construct(private readonly BookingEngine $engine) {}

    /**
     * Local dates of the rule: weekly on the weekday of starts_on, monthly on its day
     * of month (months without that day are skipped).
     *
     * @return list<CarbonImmutable> occurrence starts in UTC
     */
    public function occurrenceStarts(string $frequency, string $timezone, string $localTime, Carbon|string $startsOn, Carbon|string $untilOn): array
    {
        if (! in_array($frequency, [EventSeries::WEEKLY, EventSeries::MONTHLY], true)) {
            throw ValidationException::withMessages(['frequency' => ['重複方式只支援每週或每月。']]);
        }
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => ['時區格式錯誤。']]);
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $localTime) !== 1) {
            throw ValidationException::withMessages(['local_start_time' => ['時間格式為 HH:MM。']]);
        }

        $first = CarbonImmutable::parse(Carbon::parse($startsOn)->toDateString().' '.$localTime, $timezone);
        $until = CarbonImmutable::parse(Carbon::parse($untilOn)->toDateString().' 23:59:59', $timezone);
        if ($until->lt($first)) {
            throw ValidationException::withMessages(['until_on' => ['結束日期必須在開始日期之後。']]);
        }

        $starts = [];
        $day = (int) $first->format('j');
        for ($i = 0; ; $i++) {
            $local = $frequency === EventSeries::WEEKLY
                ? $first->addWeeks($i)
                : $first->startOfMonth()->addMonthsNoOverflow($i)->setTimeFromTimeString($localTime);

            if ($frequency === EventSeries::MONTHLY) {
                if ($day > $local->daysInMonth) {
                    if ($local->gt($until)) {
                        break;
                    }

                    continue;
                }
                $local = $local->setDay($day);
            }
            if ($local->gt($until)) {
                break;
            }

            $starts[] = $local->utc();
            if (count($starts) > EventSeries::MAX_OCCURRENCES) {
                throw ValidationException::withMessages(['until_on' => ['一個系列最多 '.EventSeries::MAX_OCCURRENCES.' 場，請縮短期間。']]);
            }
        }

        return $starts;
    }

    /**
     * @param  array<string, mixed>  $rule  frequency, timezone, local_start_time, duration_minutes, starts_on, until_on
     * @param  array<string, mixed>  $template  TEMPLATE_FIELDS
     */
    public function create(array $rule, array $template, User $actor): EventSeries
    {
        $duration = (int) ($rule['duration_minutes'] ?? 0);
        if ($duration < 1 || $duration > 24 * 60 * 7) {
            throw ValidationException::withMessages(['duration_minutes' => ['活動長度需為 1 分鐘到 7 天。']]);
        }
        $starts = $this->occurrenceStarts($rule['frequency'], $rule['timezone'], $rule['local_start_time'], $rule['starts_on'], $rule['until_on']);
        if ($starts === []) {
            throw ValidationException::withMessages(['starts_on' => ['此期間沒有任何場次。']]);
        }

        $series = DB::transaction(function () use ($rule, $template, $actor, $starts, $duration): EventSeries {
            $series = EventSeries::query()->create([
                'title' => $template['title'],
                'description' => $template['description'] ?? null,
                'frequency' => $rule['frequency'],
                'timezone' => $rule['timezone'],
                'local_start_time' => $rule['local_start_time'],
                'duration_minutes' => $duration,
                'starts_on' => $rule['starts_on'],
                'until_on' => $rule['until_on'],
                'created_by' => $actor->id,
            ]);

            $fields = array_intersect_key($template, array_flip(self::TEMPLATE_FIELDS));
            foreach ($starts as $start) {
                $this->materialize($series, $start, $start->addMinutes($duration), $fields);
            }

            return $series;
        });

        AuditEvent::record('event_series.created', $actor->id, $series, ['occurrences' => count($starts), 'frequency' => $series->frequency]);

        return $series;
    }

    /**
     * Applies $changes to the occurrence and, for future/all, to the other occurrences
     * of its series that have not started and are not single exceptions.
     * Time changes: single uses starts_at/ends_at; batches use local_start_time and
     * duration_minutes applied to each occurrence's own local date.
     *
     * @param  array<string, mixed>  $changes
     * @return array{updated: list<int>, skipped_started: list<int>, skipped_exceptions: list<int>, fixed_deadlines: list<int>, notified: int}
     */
    public function update(CalendarEvent $occurrence, string $scope, array $changes, User $actor): array
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => ['修改範圍只能是單次、此次及未來或全部。']]);
        }
        if ($scope !== 'single' && $occurrence->event_series_id === null) {
            throw ValidationException::withMessages(['scope' => ['此活動不屬於重複系列。']]);
        }

        $result = ['updated' => [], 'skipped_started' => [], 'skipped_exceptions' => [], 'fixed_deadlines' => [], 'notified' => 0];
        $targets = $this->targets($occurrence, $scope, $result);

        $fields = $scope === 'single'
            ? array_intersect_key($changes, array_flip([...self::BATCH_FIELDS, 'starts_at', 'ends_at', 'timezone']))
            : array_intersect_key($changes, array_flip(self::BATCH_FIELDS));

        $notices = [];
        DB::transaction(function () use ($targets, $scope, $fields, $changes, $occurrence, &$result, &$notices): void {
            foreach ($targets as $event) {
                /** @var CalendarEvent $locked */
                $locked = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
                $before = $locked->only(['starts_at', 'ends_at', 'location', 'floor', 'studio']);

                $values = $fields;
                if ($scope !== 'single' && (isset($changes['local_start_time']) || isset($changes['duration_minutes']))) {
                    [$start, $end] = $this->retime($locked, $changes['local_start_time'] ?? null, $changes['duration_minutes'] ?? null);
                    $values['starts_at'] = $start;
                    $values['ends_at'] = $end;
                }

                $locked->fill($values);
                if ($scope === 'single' && $locked->event_series_id !== null) {
                    $locked->is_series_exception = true;
                }
                if (array_key_exists('booking_capacity', $values) && $values['booking_capacity'] !== null
                    && $this->engine->confirmedSeats($locked) > (int) $values['booking_capacity']) {
                    throw ValidationException::withMessages(['booking_capacity' => ['場次 #'.$locked->id.' 已確認人數超過新名額，請在該場次調整名額並選擇取消對象。']]);
                }
                $locked->save();
                $result['updated'][] = $locked->id;

                $timeChanged = ! $locked->starts_at->equalTo($before['starts_at']) || ! $locked->ends_at->equalTo($before['ends_at']);
                $placeChanged = $locked->location !== $before['location'] || $locked->floor !== $before['floor'] || $locked->studio !== $before['studio'];
                if ($timeChanged && $locked->fixedDeadlines() !== []) {
                    $result['fixed_deadlines'][] = $locked->id;
                }
                if ($timeChanged || $placeChanged) {
                    $notices[] = [$locked, $timeChanged, $placeChanged];
                }

                // Capacity may have grown: promote waiting groups.
                $this->engine->promote($locked);
            }

            if ($scope !== 'single' && isset($fields['title'])) {
                $occurrence->series?->forceFill(['title' => $fields['title']])->save();
            }

            // Persist notification/sync intent in the same transaction as the
            // event change. Previously the event committed before notifyChange(),
            // leaving a crash window with no durable notification intent.
            foreach ($notices as [$event, $timeChanged, $placeChanged]) {
                $what = implode('、', array_filter([$timeChanged ? '時間' : null, $placeChanged ? '地點' : null]));
                $body = '「'.$event->title.'」的'.$what.'已變更：'.$event->starts_at->timezone($event->timezone ?? 'Asia/Taipei')->format('Y-m-d H:i')
                    .($event->location ? '，'.implode(' ', array_filter([$event->location, $event->floor, $event->studio])) : '').'。您的報名／候補維持不變。';
                $result['notified'] += $this->engine->notifyChange($event, '活動異動：'.$event->title, $body);
            }
        });

        AuditEvent::record('event.updated', $actor->id, $occurrence, [
            'scope' => $scope,
            'fields' => array_keys($fields + array_intersect_key($changes, array_flip(['local_start_time', 'duration_minutes']))),
            'updated' => $result['updated'],
            'skipped_started' => $result['skipped_started'],
            'skipped_exceptions' => $result['skipped_exceptions'],
            'fixed_deadline_kept' => $result['fixed_deadlines'],
        ]);

        return $result;
    }

    /**
     * Cancels the occurrence (single) or the not-yet-started occurrences of the
     * series (future / all). Cancelling never promotes waitlists (M08-D12).
     *
     * @return list<int> cancelled occurrence ids
     */
    public function cancel(CalendarEvent $occurrence, string $scope, ?string $reason, User $actor): array
    {
        $result = ['updated' => [], 'skipped_started' => [], 'skipped_exceptions' => [], 'fixed_deadlines' => [], 'notified' => 0];
        $targets = $scope === 'single' ? collect([$occurrence]) : $this->targets($occurrence, $scope, $result, includeExceptions: true);

        $ids = [];
        foreach ($targets as $event) {
            if ($event->status === CalendarEvent::STATUS_CANCELLED) {
                continue;
            }
            $this->engine->cancelEvent($event, $reason, $actor);
            $ids[] = $event->id;
        }

        if ($scope !== 'single') {
            AuditEvent::record('event_series.cancelled', $actor->id, $occurrence, ['scope' => $scope, 'cancelled' => $ids, 'skipped_started' => $result['skipped_started']]);
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return Collection<int, CalendarEvent>
     */
    private function targets(CalendarEvent $occurrence, string $scope, array &$result, bool $includeExceptions = false): Collection
    {
        if ($scope === 'single') {
            return collect([$occurrence]);
        }

        $query = CalendarEvent::query()->where('event_series_id', $occurrence->event_series_id)->orderBy('series_original_start_at');
        if ($scope === 'future') {
            $query->where('series_original_start_at', '>=', $occurrence->series_original_start_at);
        }

        $now = now();

        return $query->get()->filter(function (CalendarEvent $event) use ($now, $occurrence, $includeExceptions, &$result): bool {
            if ($event->starts_at->lte($now)) {
                $result['skipped_started'][] = $event->id;

                return false;
            }
            if (! $includeExceptions && $event->is_series_exception && $event->id !== $occurrence->id) {
                $result['skipped_exceptions'][] = $event->id;

                return false;
            }

            return true;
        })->values();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function retime(CalendarEvent $event, ?string $localTime, mixed $duration): array
    {
        $tz = $event->timezone ?? 'Asia/Taipei';
        $currentLocal = $event->starts_at->copy()->timezone($tz);
        $minutes = $duration !== null ? (int) $duration : (int) $event->starts_at->diffInMinutes($event->ends_at);
        if ($localTime !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $localTime) !== 1) {
            throw ValidationException::withMessages(['local_start_time' => ['時間格式為 HH:MM。']]);
        }

        $start = $localTime !== null
            ? Carbon::parse($currentLocal->toDateString().' '.$localTime, $tz)->utc()
            : $event->starts_at->copy();

        return [$start, $start->copy()->addMinutes($minutes)];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function materialize(EventSeries $series, CarbonImmutable $start, CarbonImmutable $end, array $fields): CalendarEvent
    {
        // Idempotent: UNIQUE(event_series_id, series_original_start_at).
        $existing = CalendarEvent::query()->where('event_series_id', $series->id)->where('series_original_start_at', $start)->first();
        if ($existing !== null) {
            return $existing;
        }

        $event = new CalendarEvent($fields + ['status' => CalendarEvent::STATUS_DRAFT, 'audience' => CalendarEvent::AUDIENCE_MEMBERS]);
        $event->forceFill([
            'starts_at' => $start,
            'ends_at' => $end,
            'timezone' => $series->timezone,
            'event_series_id' => $series->id,
            'series_original_start_at' => $start,
        ])->save();

        return $event;
    }
}
