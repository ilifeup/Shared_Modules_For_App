<?php

namespace App\Services\Calendar;

use App\Jobs\SyncGoogleCalendarItem;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\GoogleCalendarConnection;
use App\Models\GoogleCalendarEventMapping;
use App\Models\PrivateCalendarEvent;
use App\Models\User;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * S9 M09 one-way App → Google sync (D06). The App database stays the authority;
 * every job reads the latest state when it runs (old jobs never overwrite newer
 * changes) and writes one Google event per member connection and source (D25).
 */
class GoogleCalendarSync
{
    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly CalendarVip $vip,
    ) {}

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function connectionOf(User $user): ?GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->where('user_id', $user->id)->first();
    }

    /**
     * Starts OAuth: returns the Google consent URL with a one-time state (10 minutes).
     */
    public function authorizationUrl(User $user): string
    {
        if (! $this->configured()) {
            throw new ApiException(503, 'GOOGLE_NOT_CONFIGURED', '此 App 尚未設定 Google 日曆連結。');
        }

        $state = Str::random(40);
        Cache::put('google-oauth-state:'.$state, $user->id, now()->addMinutes(10));

        return $this->client->authorizationUrl($state);
    }

    /**
     * OAuth callback: exchanges the code, keeps the encrypted refresh token, creates
     * the App's own calendar (D17) and queues current and future items (D24).
     */
    public function completeAuthorization(string $state, string $code): User
    {
        $userId = Cache::pull('google-oauth-state:'.$state);
        $user = $userId !== null ? User::query()->find($userId) : null;
        if ($user === null || ! $user->is_active) {
            throw new ApiException(422, 'GOOGLE_STATE_INVALID', '連結已逾時，請回到 App 重新連結。');
        }

        $token = $this->client->exchangeCode($code);

        $connection = GoogleCalendarConnection::query()->firstOrNew(['user_id' => $user->id]);
        $connection->forceFill([
            'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
            'access_token' => $token['access_token'],
            'access_token_expires_at' => now()->addSeconds(max(60, $token['expires_in'] - 60)),
            'status' => GoogleCalendarConnection::ACTIVE,
            'connected_at' => now(),
            'disconnected_at' => null,
            'last_error' => null,
            'sync_from' => now(),
        ])->save();

        if ($connection->google_calendar_id === null) {
            $connection->forceFill([
                'google_calendar_id' => $this->client->createCalendar($connection, (string) config('google_calendar.calendar_name'), app(Settings::class)->string('calendar_default_timezone')),
            ])->save();
        }

        AuditEvent::record('google_calendar.connected', $user->id, $connection);
        $this->queueAll($connection);

        return $user;
    }

    /**
     * Stops syncing and forgets the tokens; events already in Google stay (D13).
     */
    public function disconnect(User $user): void
    {
        $connection = $this->connectionOf($user);
        if ($connection === null || $connection->status === GoogleCalendarConnection::DISCONNECTED) {
            return;
        }

        $connection->forceFill([
            'status' => GoogleCalendarConnection::DISCONNECTED,
            'refresh_token' => null,
            'access_token' => null,
            'access_token_expires_at' => null,
            'disconnected_at' => now(),
        ])->save();
        AuditEvent::record('google_calendar.disconnected', $user->id, $connection);
    }

    public function queueBooking(int $userId, int $eventId): void
    {
        $connection = GoogleCalendarConnection::query()->where('user_id', $userId)->where('status', GoogleCalendarConnection::ACTIVE)->first();
        if ($connection !== null) {
            $this->dispatch($connection, GoogleCalendarEventMapping::SOURCE_BOOKING, $eventId);
        }
    }

    public function queuePrivate(PrivateCalendarEvent|int $entry, ?int $userId = null): void
    {
        $id = $entry instanceof PrivateCalendarEvent ? $entry->id : $entry;
        $userId ??= $entry instanceof PrivateCalendarEvent ? $entry->user_id : null;
        $connection = GoogleCalendarConnection::query()->where('user_id', $userId)->where('status', GoogleCalendarConnection::ACTIVE)->first();
        if ($connection !== null) {
            $this->dispatch($connection, GoogleCalendarEventMapping::SOURCE_PRIVATE, $id);
        }
    }

    /** Admin "retry now" for a failed item (L03 A). */
    public function retry(GoogleCalendarEventMapping $mapping, User $admin): void
    {
        $mapping->forceFill(['status' => 'pending', 'attempts' => 0, 'last_error' => null])->save();
        AuditEvent::record('google_calendar.retry', $admin->id, $mapping);
        SyncGoogleCalendarItem::dispatch($mapping->google_calendar_connection_id, $mapping->source_type, $mapping->source_id);
    }

    /**
     * Current and future confirmed bookings and private entries (no back-fill, D24).
     */
    public function queueAll(GoogleCalendarConnection $connection): void
    {
        $eventIds = EventBookingGroup::query()
            ->where('booker_user_id', $connection->user_id)
            ->where('status', EventBookingGroup::STATUS_CONFIRMED)
            ->whereHas('event', fn ($q) => $q->where('ends_at', '>=', now()))
            ->pluck('calendar_event_id')->unique();
        foreach ($eventIds as $eventId) {
            $this->dispatch($connection, GoogleCalendarEventMapping::SOURCE_BOOKING, (int) $eventId);
        }

        $privateIds = PrivateCalendarEvent::query()->where('user_id', $connection->user_id)
            ->where(fn ($q) => $q->where('ends_at', '>=', now())->orWhere('recurrence_until', '>=', now()->toDateString()))
            ->pluck('id');
        foreach ($privateIds as $id) {
            $this->dispatch($connection, GoogleCalendarEventMapping::SOURCE_PRIVATE, (int) $id);
        }
    }

    /**
     * Runs one item (called by the job). Returns the resulting mapping status.
     */
    public function sync(GoogleCalendarConnection $connection, string $sourceType, int $sourceId): string
    {
        if (! $connection->isActive()) {
            return 'skipped';
        }

        // One item at a time: a slow upsert must not finish after a later delete and
        // leave a cancelled booking in Google (jobs for the same item may overlap).
        return Cache::lock('google-sync:'.$connection->id.':'.$sourceType.':'.$sourceId, 120)
            ->block(60, fn (): string => $this->syncLocked($connection, $sourceType, $sourceId));
    }

    private function syncLocked(GoogleCalendarConnection $connection, string $sourceType, int $sourceId): string
    {
        $mapping = $this->mapping($connection, $sourceType, $sourceId);
        $desired = $sourceType === GoogleCalendarEventMapping::SOURCE_BOOKING
            ? $this->bookingPayload($connection, $sourceId)
            : $this->privatePayload($connection, $sourceId, $mapping);

        if ($desired === 'keep') {
            return $mapping->status; // VIP lapsed: stop syncing, keep the Google event (D18)
        }

        if ($desired === null) {
            // Also after an earlier failure: the event may exist although the call failed
            // (a missing event answers 404 and is ignored).
            if ($mapping->status !== 'removed') {
                $this->client->deleteEvent($connection, $mapping->google_event_id);
            }
            $mapping->forceFill(['status' => 'removed', 'synced_at' => now(), 'last_error' => null])->save();
        } else {
            $this->client->upsertEvent($connection, $mapping->google_event_id, $desired);
            $mapping->forceFill(['status' => 'synced', 'synced_at' => now(), 'last_error' => null])->save();
        }

        $connection->forceFill(['last_synced_at' => now()])->saveQuietly();

        return $mapping->status;
    }

    public function markFailed(int $connectionId, string $sourceType, int $sourceId, string $error, bool $authFailure = false): void
    {
        $connection = GoogleCalendarConnection::query()->find($connectionId);
        if ($connection === null) {
            return;
        }

        $this->mapping($connection, $sourceType, $sourceId)->forceFill(['status' => 'failed', 'last_error' => mb_substr($error, 0, 250)])->save();
        if ($authFailure) {
            $connection->forceFill(['status' => GoogleCalendarConnection::ERROR, 'last_error' => '需要重新連結 Google 日曆'])->save();
        }
    }

    public function noteAttempt(int $connectionId, string $sourceType, int $sourceId): void
    {
        GoogleCalendarEventMapping::query()->where('google_calendar_connection_id', $connectionId)
            ->where('source_type', $sourceType)->where('source_id', $sourceId)->increment('attempts');
    }

    /** Deterministic Google event id (base32hex: 0-9 a-v; hex is a subset). */
    public static function googleEventId(GoogleCalendarConnection $connection, string $sourceType, int $sourceId): string
    {
        return 'ec'.sha1(config('app.key').'|'.$connection->id.'|'.$sourceType.'|'.$sourceId);
    }

    private function mapping(GoogleCalendarConnection $connection, string $sourceType, int $sourceId): GoogleCalendarEventMapping
    {
        return DB::transaction(fn () => GoogleCalendarEventMapping::query()->lockForUpdate()->firstOrCreate(
            ['google_calendar_connection_id' => $connection->id, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['google_event_id' => self::googleEventId($connection, $sourceType, $sourceId), 'status' => 'pending'],
        ));
    }

    private function dispatch(GoogleCalendarConnection $connection, string $sourceType, int $sourceId): void
    {
        $this->mapping($connection, $sourceType, $sourceId);
        SyncGoogleCalendarItem::dispatch($connection->id, $sourceType, $sourceId);
    }

    /**
     * @return array<string, mixed>|null null = should not exist in Google
     */
    private function bookingPayload(GoogleCalendarConnection $connection, int $eventId): ?array
    {
        $event = CalendarEvent::query()->find($eventId);
        $booked = $event !== null && EventBookingGroup::query()->where('calendar_event_id', $eventId)
            ->where('booker_user_id', $connection->user_id)->where('status', EventBookingGroup::STATUS_CONFIRMED)->exists();

        if (! $booked || $event->status !== CalendarEvent::STATUS_PUBLISHED) {
            return null;
        }

        return [
            'summary' => $event->title,
            'description' => (string) $event->description,
            'location' => implode(' ', array_filter([$event->location, $event->floor, $event->studio])),
            'start' => ['dateTime' => $event->starts_at->toIso8601String(), 'timeZone' => $event->timezone ?? 'Asia/Taipei'],
            'end' => ['dateTime' => $event->ends_at->toIso8601String(), 'timeZone' => $event->timezone ?? 'Asia/Taipei'],
            // Reminders follow the member's Google calendar settings (D20).
            'reminders' => ['useDefault' => true],
        ];
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function privatePayload(GoogleCalendarConnection $connection, int $entryId, GoogleCalendarEventMapping $mapping): array|string|null
    {
        $entry = PrivateCalendarEvent::query()->where('user_id', $connection->user_id)->find($entryId);
        $user = $connection->user()->first();

        if ($user === null || ! $this->vip->isVip($user)) {
            return 'keep';
        }
        if ($entry === null) {
            return null;
        }

        $payload = [
            'summary' => $entry->title,
            'description' => (string) $entry->notes,
            'start' => ['dateTime' => $entry->starts_at->toIso8601String(), 'timeZone' => $entry->timezone],
            'end' => ['dateTime' => $entry->ends_at->toIso8601String(), 'timeZone' => $entry->timezone],
            'visibility' => 'private', // D15
            'reminders' => ['useDefault' => true],
        ];
        if ($entry->recurrence !== null) {
            $until = $entry->recurrence_until?->copy()->endOfDay()->utc()->format('Ymd\THis\Z');
            $payload['recurrence'] = ['RRULE:FREQ='.strtoupper($entry->recurrence).($until ? ';UNTIL='.$until : '')];
        }

        return $payload;
    }
}
