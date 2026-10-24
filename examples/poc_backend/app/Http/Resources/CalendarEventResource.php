<?php

namespace App\Http\Resources;

use App\Models\CalendarEvent;
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
        ];
    }
}
