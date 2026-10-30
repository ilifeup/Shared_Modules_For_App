<?php

namespace App\Console\Commands;

use App\Models\CalendarEvent;
use App\Services\Booking\CanonicalCutoverAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Read-only cutover inventory. Never changes booking data. */
final class AuditCanonicalBookingCutover extends Command
{
    protected $signature = 'booking:audit-canonical-cutover
        {--event= : Audit exactly one calendar event ID}
        {--limit=500 : Maximum events to inspect per run (1-5000)}
        {--after-id=0 : Continue scanning after this event ID}';

    protected $description = 'Read-only canonical booking cutover inventory (JSON Lines)';

    public function handle(CanonicalCutoverAudit $audit): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 5000) {
            $this->error('--limit must be between 1 and 5000');
            return self::INVALID;
        }
        $idOption = $this->option('event');
        $eventId = null;
        if ($idOption !== null) {
            $eventId = filter_var($idOption, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($eventId === false) {
                $this->error('--event must be a positive integer');
                return self::INVALID;
            }
        }
        $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($afterId === false || ($eventId !== null && $afterId !== 0)) {
            $this->error('--after-id must be a non-negative integer and cannot be combined with --event');
            return self::INVALID;
        }
        foreach (['calendar_events', 'event_bookings'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing required table: {$table}");
                return self::FAILURE;
            }
        }
        $query = CalendarEvent::query()->orderBy('id');
        if ($eventId !== null) {
            $query->whereKey($eventId);
        } elseif ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }
        $total = 0;
        $blocked = 0;
        $failed = 0;
        $truncated = false;
        $lastEventId = null;
        // Each event is read independently; no writes, transactions or production changes.
        foreach ($query->limit($limit + ($eventId === null ? 1 : 0))->cursor() as $event) {
            if ($total === $limit) {
                $truncated = true;
                break;
            }
            $total++;
            $lastEventId = (int) $event->id;
            try {
                $result = $audit->inspect($event);
                if (! $result['ready_for_free_canonical_booking']) {
                    $blocked++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $result = [
                    'calendar_event_id' => (int) $event->id,
                    'ready_for_free_canonical_booking' => false,
                    'issues' => ['audit_exception'],
                    'error_class' => get_class($exception),
                ];
            }
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        }
        $this->line(json_encode(['summary' => [
            'inspected' => $total, 'blocked' => $blocked, 'errors' => $failed,
            'truncated_at_limit' => $truncated,
            'last_event_id' => $lastEventId,
            'next_after_id' => $truncated ? $lastEventId : null,
            'read_only' => true,
        ]], JSON_THROW_ON_ERROR));
        if ($eventId !== null && $total === 0) {
            return self::FAILURE;
        }
        // Never report a successful full audit when more events remain.
        return ($blocked || $failed || $truncated) ? self::FAILURE : self::SUCCESS;
    }
}
