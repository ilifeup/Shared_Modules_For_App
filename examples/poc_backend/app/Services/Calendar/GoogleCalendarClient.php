<?php

namespace App\Services\Calendar;

use App\Models\GoogleCalendarConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google OAuth 2.0 + Calendar API v3 client (Laravel HTTP client, no SDK).
 * Errors carry the HTTP status only — never tokens or response bodies.
 */
class GoogleCalendarClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public function configured(): bool
    {
        return filled(config('google_calendar.client_id')) && filled(config('google_calendar.client_secret')) && filled(config('google_calendar.redirect_uri'));
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('google_calendar.client_id'),
            'redirect_uri' => config('google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('google_calendar.scope'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('google_calendar.redirect_uri')]);
    }

    public function accessToken(GoogleCalendarConnection $connection): string
    {
        if ($connection->access_token !== null && $connection->access_token_expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }
        if ($connection->refresh_token === null) {
            throw new GoogleCalendarException('Google connection has no refresh token.', 401);
        }

        $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        $connection->forceFill([
            'access_token' => $token['access_token'],
            'access_token_expires_at' => now()->addSeconds(max(60, $token['expires_in'] - 60)),
        ])->save();

        return $token['access_token'];
    }

    public function createCalendar(GoogleCalendarConnection $connection, string $name, string $timezone): string
    {
        $response = $this->api($connection)->post(self::API.'/calendars', ['summary' => $name, 'timeZone' => $timezone]);
        $this->guard($response->status(), 'create calendar');

        return (string) $response->json('id');
    }

    /**
     * Create-or-update with a deterministic event id (no duplicates on retry).
     *
     * @param  array<string, mixed>  $event
     */
    public function upsertEvent(GoogleCalendarConnection $connection, string $eventId, array $event): void
    {
        $calendar = rawurlencode((string) $connection->google_calendar_id);
        $body = $event + ['id' => $eventId, 'status' => 'confirmed'];

        $update = $this->api($connection)->put(self::API."/calendars/{$calendar}/events/{$eventId}", $body);
        if ($update->status() === 404) {
            $insert = $this->api($connection)->post(self::API."/calendars/{$calendar}/events", $body);
            if ($insert->status() === 409) { // created meanwhile by a parallel attempt
                $update = $this->api($connection)->put(self::API."/calendars/{$calendar}/events/{$eventId}", $body);
                $this->guard($update->status(), 'update event');

                return;
            }
            $this->guard($insert->status(), 'insert event');

            return;
        }
        $this->guard($update->status(), 'update event');
    }

    public function deleteEvent(GoogleCalendarConnection $connection, string $eventId): void
    {
        $calendar = rawurlencode((string) $connection->google_calendar_id);
        $response = $this->api($connection)->delete(self::API."/calendars/{$calendar}/events/{$eventId}");
        if (in_array($response->status(), [404, 410], true)) {
            return; // already gone
        }
        $this->guard($response->status(), 'delete event');
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function token(array $params): array
    {
        try {
            $response = Http::asForm()->timeout((int) config('google_calendar.timeout', 15))->post(self::TOKEN_URL, $params + [
                'client_id' => config('google_calendar.client_id'),
                'client_secret' => config('google_calendar.client_secret'),
            ]);
        } catch (ConnectionException) {
            throw new GoogleCalendarException('Google token request failed (connection).', 0);
        }
        $this->guard($response->status(), 'token');

        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw new GoogleCalendarException('Google token response had no access token.', $response->status());
        }

        return [
            'access_token' => $access,
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
        ];
    }

    private function api(GoogleCalendarConnection $connection): PendingRequest
    {
        return Http::withToken($this->accessToken($connection))->acceptJson()->timeout((int) config('google_calendar.timeout', 15));
    }

    private function guard(int $status, string $what): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new GoogleCalendarException("Google {$what} answered HTTP {$status}.", $status);
    }
}
