<?php

namespace App\Services\Calendar;

use App\Models\AuditEvent;
use App\Models\PrivateCalendarEvent;
use App\Models\User;
use App\Support\Api\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * S9 M09 VIP private entries: create / update / delete only while the member is a
 * "高級 VIP" (D05, D22); afterwards existing entries stay readable but fully
 * read-only and their sync stops (D08, D14, D18).
 */
class PrivateCalendarService
{
    public const MAX_EXPANDED = 400;

    public function __construct(
        private readonly CalendarVip $vip,
        private readonly GoogleCalendarSync $google,
    ) {}

    /**
     * @param  array{title: string, notes?: string|null, starts_at: Carbon, ends_at: Carbon, timezone: string, recurrence?: string|null, recurrence_until?: string|null}  $data
     */
    public function create(User $member, array $data): PrivateCalendarEvent
    {
        $this->assertVip($member);
        $entry = PrivateCalendarEvent::query()->create($this->clean($data) + ['user_id' => $member->id]);
        AuditEvent::record('calendar.private_created', $member->id, $entry);
        $this->google->queuePrivate($entry);

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $member, PrivateCalendarEvent $entry, array $data): PrivateCalendarEvent
    {
        $this->assertOwner($member, $entry);
        $this->assertVip($member);
        $entry->fill($this->clean($data + $entry->only(['title', 'notes', 'starts_at', 'ends_at', 'timezone', 'recurrence', 'recurrence_until'])))->save();
        AuditEvent::record('calendar.private_updated', $member->id, $entry);
        $this->google->queuePrivate($entry);

        return $entry;
    }

    public function delete(User $member, PrivateCalendarEvent $entry): void
    {
        $this->assertOwner($member, $entry);
        $this->assertVip($member);
        $id = $entry->id;
        $entry->delete();
        AuditEvent::record('calendar.private_deleted', $member->id, null, ['private_event_id' => $id]);
        $this->google->queuePrivate($id, $member->id);
    }

    /**
     * Occurrences overlapping [$from, $to) (weekly / monthly expansion in the entry's zone).
     *
     * @return list<array{entry: PrivateCalendarEvent, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function expand(User $member, Carbon $from, Carbon $to): array
    {
        $entries = PrivateCalendarEvent::query()->where('user_id', $member->id)
            ->where('starts_at', '<', $to)
            ->where(fn ($q) => $q->where('ends_at', '>', $from)->orWhereNotNull('recurrence'))
            ->orderBy('starts_at')->get();

        $out = [];
        foreach ($entries as $entry) {
            $start = CarbonImmutable::instance($entry->starts_at)->timezone($entry->timezone);
            $length = $entry->starts_at->diffInSeconds($entry->ends_at);
            $until = $entry->recurrence_until !== null ? CarbonImmutable::parse($entry->recurrence_until->toDateString().' 23:59:59', $entry->timezone) : null;
            $day = (int) $start->format('j');

            for ($i = 0; $i < 1000; $i++) {
                $s = match ($entry->recurrence) {
                    'weekly' => $start->addWeeks($i),
                    'monthly' => $start->startOfMonth()->addMonthsNoOverflow($i)->setTimeFrom($start),
                    default => $i === 0 ? $start : null,
                };
                if ($s === null || ($until !== null && $s->gt($until)) || $s->gte($to)) {
                    break;
                }
                if ($entry->recurrence === 'monthly') {
                    if ($day > $s->daysInMonth) {
                        continue;
                    }
                    $s = $s->setDay($day);
                }
                $e = $s->addSeconds((int) $length);
                if ($e->gt($from)) {
                    $out[] = ['entry' => $entry, 'starts_at' => $s->utc(), 'ends_at' => $e->utc()];
                }
                if (count($out) >= self::MAX_EXPANDED) {
                    return $out;
                }
            }
        }

        usort($out, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

        return $out;
    }

    private function assertVip(User $member): void
    {
        if (! $this->vip->isVip($member)) {
            throw new ApiException(403, 'VIP_REQUIRED', '私人行程僅限高級 VIP 會員新增或修改；既有行程仍可查看。');
        }
    }

    private function assertOwner(User $member, PrivateCalendarEvent $entry): void
    {
        if ($entry->user_id !== $member->id) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此行程。');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $starts = Carbon::parse($data['starts_at'])->utc();
        $ends = Carbon::parse($data['ends_at'])->utc();
        $errors = array_filter([
            'title' => trim((string) $data['title']) === '' || mb_strlen((string) $data['title']) > 120 ? ['標題需為 1–120 字。'] : null,
            'ends_at' => ! $ends->greaterThan($starts) ? ['結束時間必須晚於開始時間。'] : null,
            'timezone' => ! in_array($data['timezone'] ?? '', timezone_identifiers_list(), true) ? ['時區格式錯誤。'] : null,
            'recurrence' => isset($data['recurrence']) && ! in_array($data['recurrence'], ['weekly', 'monthly'], true) ? ['重複只支援每週或每月。'] : null,
        ]);
        if ($errors !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', '輸入資料有誤。', $errors);
        }

        return [
            'title' => trim((string) $data['title']),
            'notes' => isset($data['notes']) ? mb_substr((string) $data['notes'], 0, 2000) : null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'timezone' => $data['timezone'],
            'recurrence' => $data['recurrence'] ?? null,
            'recurrence_until' => isset($data['recurrence']) ? ($data['recurrence_until'] ?? null) : null,
        ];
    }
}
