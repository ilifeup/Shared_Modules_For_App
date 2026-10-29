<?php

namespace App\Http\Resources;

use App\Models\CalendarEvent;
use App\Support\ContentRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CalendarEvent
 */
class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Stable id (future S6 bookings refer to it).
            'id' => (string) $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            // Always UTC (Z); Apps convert to local time.
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'status' => $this->status,
            'audience' => $this->audience,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason' => $this->cancel_reason,
            // S9 (additive): venue (one set per occurrence), zone, category colour, series.
            'floor' => $this->floor,
            'studio' => $this->studio,
            'timezone' => $this->timezone ?? 'Asia/Taipei',
            'category' => $this->category === null ? null : [
                'id' => (string) $this->category->id,
                'name' => $this->category->name,
                'color' => $this->category->color,
            ],
            'series_id' => $this->event_series_id !== null ? (string) $this->event_series_id : null,
            'checkin_enabled' => (bool) $this->checkin_enabled,
            'waitlist_enabled' => (bool) $this->waitlist_enabled,
            'waitlist_capacity' => (int) ($this->waitlist_capacity ?? 5),
            'share_url' => app(ContentRegistry::class)->webUrl('event', (int) $this->id),
        ];
    }
}
