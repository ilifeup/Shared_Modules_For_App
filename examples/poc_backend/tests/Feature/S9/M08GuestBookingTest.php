<?php

use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Support\Api\ApiException;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function guestEvent(array $attributes = []): CalendarEvent
{
    $category = EventCategory::query()->firstOrCreate(['name' => '開放活動'], ['allow_guest_booking' => true, 'allow_proxy_booking' => true]);

    return s9Event(array_merge(['audience' => CalendarEvent::AUDIENCE_PUBLIC, 'booking_capacity' => 2], $attributes), $category);
}

function guestPost(CalendarEvent $event, array $data)
{
    return test()->post("/events/{$event->id}/guest-booking", array_merge([
        'contact_name' => '王訪客',
        'contact_email' => 'Guest@Example.com',
        'attendees' => ['王訪客', '王小弟'],
        'client_request_id' => (string) Str::uuid(),
    ], $data));
}

it('rejects a guest replay with a changed contact name but preserves the original booking', function () {
    $event = guestEvent(['booking_capacity' => 3]);
    $id = (string) Str::uuid();
    $engine = app(BookingEngine::class);

    $request = fn (string $contactName) => new BookingRequest(
        booker: null,
        contactName: $contactName,
        contactEmail: 'guest@example.com',
        contactPhone: null,
        attendees: [['name' => '王訪客']],
        clientRequestId: $id,
    );

    $first = $engine->book($event, $request('王訪客'));
    expect($engine->book($event, $request('王訪客'))->id)->toBe($first->id);

    try {
        $engine->book($event, $request('不同聯絡人'));
        test()->fail('Changed guest contact name must conflict with the original idempotency key.');
    } catch (ApiException $e) {
        expect($e->status)->toBe(409)
            ->and($e->errorCode)->toBe('IDEMPOTENCY_CONFLICT');
    }

    expect(EventBookingGroup::query()->count())->toBe(1)
        ->and($first->refresh()->contact_name)->toBe('王訪客')
        ->and(seats($event))->toBe(1);
});

// G03 / G15 / G16 — guests book with name + email or phone; no email/SMS; claim after verification.
it('lets guests book on the web page without sending any email or SMS', function () {
    Mail::fake();
    $event = guestEvent();

    $this->get("/events/{$event->id}")->assertOk()->assertSee('訪客報名')->assertSee('og:title', false);
    guestPost($event, [])->assertOk()->assertSee('【報名成功】');

    $group = EventBookingGroup::query()->sole();
    expect($group->is_guest)->toBeTrue()
        ->and($group->booker_user_id)->toBeNull()
        ->and($group->contact_email)->toBe('guest@example.com')
        ->and($group->seat_count)->toBe(2)
        ->and(seats($event))->toBe(2);
    Mail::assertNothingSent();

    // Full now: the next guest is waitlisted, still no message sent.
    guestPost($event, ['contact_email' => 'other@example.com', 'attendees' => ['某人']])->assertOk()->assertSee('【已列入候補】');
    Mail::assertNothingSent();
});

it('requires an email or phone and refuses guests where the category does not allow them', function () {
    $event = guestEvent();
    guestPost($event, ['contact_email' => null])->assertSessionHasErrors('contact_email');

    $members = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC], EventCategory::query()->create(['name' => '會員限定', 'allow_guest_booking' => false]));
    $this->get("/events/{$members->id}")->assertOk()->assertDontSee('訪客報名');
    guestPost($members, [])->assertNotFound();
    expect(EventBookingGroup::query()->count())->toBe(0);
});

it('keeps guest notifications on the booking and moves them when a verified member claims it', function () {
    $event = guestEvent(['booking_capacity' => 1]);
    $holder = User::factory()->create();
    groupBook($event, $holder);
    guestPost($event, ['attendees' => ['王訪客']])->assertSee('【已列入候補】');
    $guestGroup = EventBookingGroup::query()->where('is_guest', true)->sole();

    // Promotion while the guest is not a member: the notice stays on the booking (D23).
    $holderGroup = EventBookingGroup::query()->where('booker_user_id', $holder->id)->sole();
    app(BookingEngine::class)->cancelGroup($holderGroup, $holder);
    expect($guestGroup->refresh()->status)->toBe('confirmed')
        ->and($guestGroup->notifications()->count())->toBe(1);

    // Another member with a different verified email sees nothing and cannot claim it.
    $stranger = User::factory()->create(['email' => 'stranger@example.com']);
    $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/me/guest-bookings')->assertJsonCount(0, 'data');
    $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/me/guest-bookings/{$guestGroup->id}/claim")->assertNotFound();

    // Same email but not verified: not claimable (D24 needs the original contact verified).
    $unverified = User::factory()->unverified()->create(['email' => 'guest@example.com']);
    $this->actingAs($unverified, 'sanctum')->getJson('/api/v1/me/guest-bookings')->assertStatus(403);

    // Verified owner of the email claims it; the stored notification moves to the member.
    $unverified->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($unverified, 'sanctum')->getJson('/api/v1/me/guest-bookings')->assertJsonCount(1, 'data');
    $this->actingAs($unverified, 'sanctum')->postJson("/api/v1/me/guest-bookings/{$guestGroup->id}/claim")->assertOk()->assertJsonPath('data.status', 'confirmed');

    expect($guestGroup->refresh()->booker_user_id)->toBe($unverified->id)
        ->and($unverified->notifications()->count())->toBe(1)
        ->and($guestGroup->notifications()->count())->toBe(0);
    $this->actingAs($unverified, 'sanctum')->getJson('/api/v1/me/booking-groups')->assertJsonPath('meta.total', 1);
});

it('matches guests by verified phone and refuses a claim that would double-book the member', function () {
    $event = guestEvent(['booking_capacity' => 5]);
    guestPost($event, ['contact_email' => null, 'contact_phone' => '0912-345-678', 'attendees' => ['陳訪客']]);
    $group = EventBookingGroup::query()->sole();
    expect($group->contact_phone)->toBe('+886912345678');

    $member = User::factory()->phoneOnly('+886912345678')->create();
    app(Settings::class)->put(['verification_policy' => 'phone_required']);
    groupBook($event, $member)->assertCreated();
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/guest-bookings/{$group->id}/claim")
        ->assertStatus(409)->assertJsonPath('error.code', 'CLAIM_CONFLICT');
});
