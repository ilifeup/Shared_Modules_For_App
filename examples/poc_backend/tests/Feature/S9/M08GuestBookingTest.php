<?php

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Api\ApiException;
use Illuminate\Support\Str;

it('never exposes guest registration even when legacy category flag is enabled', function () {
    $category = EventCategory::query()->create(['name' => 'legacy-guest-open', 'allow_guest_booking' => true]);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC], $category);
    $this->get("/events/{$event->id}")->assertOk()->assertDontSee('訪客報名');
    $this->post("/events/{$event->id}/guest-booking", [
        'contact_name' => '訪客', 'contact_email' => 'guest@example.com',
        'attendees' => ['訪客'], 'client_request_id' => (string) Str::uuid(),
    ])->assertNotFound();
    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('rejects guest booking directly in the engine even for an admin request', function () {
    $category = EventCategory::query()->create(['name' => 'legacy-guest-admin', 'allow_guest_booking' => true]);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC], $category);
    try {
        app(BookingEngine::class)->book($event, new BookingRequest(
            booker: null, contactName: '訪客', contactEmail: 'guest@example.com', contactPhone: null,
            attendees: [['name' => '訪客', 'member_user_id' => null]], byAdmin: true,
        ));
        test()->fail('Guest booking should have been rejected');
    } catch (ApiException $e) {
        expect($e->getMessage())->toContain('不開放訪客');
    }
    expect(EventBookingGroup::query()->count())->toBe(0);
});
