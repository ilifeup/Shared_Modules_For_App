<?php

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Api\ApiException;
use Illuminate\Support\Str;

it('never exposes guest registration for public events without any guest setting', function () {
    $category = EventCategory::query()->create(['name' => 'members-only-public-event']);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC], $category);
    $this->get("/events/{$event->id}")->assertOk()->assertDontSee('訪客報名');
    $this->post("/events/{$event->id}/guest-booking", [
        'contact_name' => '訪客', 'contact_email' => 'guest@example.com',
        'attendees' => ['訪客'], 'client_request_id' => (string) Str::uuid(),
    ])->assertNotFound();
    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('rejects guest booking directly in the engine even for an admin request', function () {
    $category = EventCategory::query()->create(['name' => 'members-only-admin-event']);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC], $category);
    try {
        app(BookingEngine::class)->book($event, new BookingRequest(
            booker: null, contactName: '訪客', contactEmail: 'guest@example.com', contactPhone: null,
            attendees: [['name' => '訪客', 'member_user_id' => null]], byAdmin: true,
        ));
        test()->fail('Guest booking should have been rejected');
    } catch (ApiException $e) {
        expect($e->status)->toBe(403)
            ->and($e->errorCode)->toBe('GUEST_NOT_ALLOWED');
    }
    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('requires authentication and rejects nonexistent attendee member IDs', function () {
    $event = s9Event();
    $url = "/api/v1/events/{$event->id}/booking-groups";
    $payload = [
        'include_self' => false,
        'attendees' => [['member_user_id' => 2147483647]],
        'client_request_id' => (string) Str::uuid(),
    ];

    $this->postJson($url, $payload)->assertUnauthorized();

    $member = User::factory()->create();
    $this->actingAs($member, 'sanctum')->postJson($url, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['attendees.0.member_user_id']);
    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('rejects name-only attendees and arbitrary member identities in the group API', function () {
    $event = s9Event();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $url = "/api/v1/events/{$event->id}/booking-groups";

    $this->actingAs($member, 'sanctum')->postJson($url, [
        'include_self' => false,
        'attendees' => [['name' => '無會員 ID']],
        'client_request_id' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonValidationErrors(['attendees.0.member_user_id']);

    $this->actingAs($member, 'sanctum')->postJson($url, [
        'include_self' => false,
        'attendees' => [['member_user_id' => $other->id]],
        'client_request_id' => (string) Str::uuid(),
    ])->assertStatus(403)->assertJsonPath('error.code', 'ATTENDEE_NOT_AUTHORIZED');

    $this->actingAs($member, 'sanctum')->postJson($url, [
        'include_self' => true,
        'attendees' => [['member_user_id' => $member->id]],
        'client_request_id' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_ATTENDEE');

    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('rejects name-only and duplicate member identities for direct engine callers', function () {
    $event = s9Event();
    $member = User::factory()->create();
    $request = fn (array $attendees) => new BookingRequest(
        booker: $member, contactName: $member->name,
        contactEmail: $member->email, contactPhone: $member->phone_e164,
        attendees: $attendees, byAdmin: true,
    );

    expect(fn () => app(BookingEngine::class)->book($event, $request([
        ['name' => '沒有會員 ID', 'member_user_id' => null],
    ])))->toThrow(ApiException::class, '會員 ID');

    expect(fn () => app(BookingEngine::class)->book($event, $request([
        ['name' => $member->name, 'member_user_id' => $member->id],
        ['name' => $member->name, 'member_user_id' => $member->id],
    ])))->toThrow(ApiException::class, '重複加入');

    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('does not expose guest claim endpoints after guest bookings are retired', function () {
    $member = User::factory()->create();
    $this->actingAs($member, 'sanctum')
        ->postJson('/api/v1/me/guest-bookings/123/claim')
        ->assertNotFound();
});
