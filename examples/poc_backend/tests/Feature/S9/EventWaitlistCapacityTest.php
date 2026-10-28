<?php

namespace Tests\Feature\S9;

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventWaitlistCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_events_default_to_five_waitlist_places_independently(): void
    {
        $first = $this->event('Standalone activity');
        $second = $this->event('Standalone course');

        $this->assertSame(5, $first->waitlist_capacity);
        $this->assertSame(5, $second->waitlist_capacity);
    }

    public function test_administrator_can_set_zero_waitlist_places(): void
    {
        $event = $this->event('No waitlist');
        $event->update(['waitlist_capacity' => 0]);

        $this->assertSame(0, $event->fresh()->waitlist_capacity);
    }

    private function event(string $title): CalendarEvent
    {
        return CalendarEvent::query()->create([
            'title' => $title,
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHour(),
            'status' => CalendarEvent::STATUS_DRAFT,
            'audience' => CalendarEvent::AUDIENCE_MEMBERS,
            'booking_enabled' => true,
            'booking_capacity' => 20,
            'waitlist_enabled' => true,
        ]);
    }
}
