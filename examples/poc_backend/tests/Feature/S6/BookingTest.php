<?php

use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\ListCalendarEvents;
use App\Filament\Resources\CalendarEvents\RelationManagers\BookingsRelationManager;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBooking;
use App\Models\User;
use App\Services\BookingService;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function bookableEvent(array $attributes = []): CalendarEvent
{
    return CalendarEvent::query()->create(array_merge([
        'title' => '可預約活動',
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
        'status' => CalendarEvent::STATUS_PUBLISHED,
        'audience' => CalendarEvent::AUDIENCE_MEMBERS,
        'booking_enabled' => true,
        'booking_capacity' => 2,
    ], $attributes));
}

function book(CalendarEvent $event, User $member)
{
    return test()->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings");
}

it('books, shows the seats, cancels and books again', function () {
    $event = bookableEvent();
    $member = User::factory()->create();

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$event->id}/booking")
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.capacity', 2)
        ->assertJsonPath('data.remaining', 2)
        ->assertJsonPath('data.unavailable_reason', null)
        ->assertJsonPath('data.my_booking', null);

    $created = book($event, $member)
        ->assertCreated()
        ->assertJsonPath('data.booked_count', 1)
        ->assertJsonPath('data.remaining', 1)
        ->assertJsonPath('data.unavailable_reason', 'already_booked')
        ->assertJsonPath('data.my_booking.status', 'confirmed');
    $bookingId = $created->json('data.my_booking.id');

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/bookings')
        ->assertOk()
        ->assertJsonPath('data.0.id', $bookingId)
        ->assertJsonPath('data.0.event.id', (string) $event->id);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/bookings/{$bookingId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.my_booking', null)
        ->assertJsonPath('data.remaining', 2);

    // Repeated cancel is answered explicitly.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/bookings/{$bookingId}/cancel")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_NOT_ACTIVE');

    book($event, $member)->assertCreated();

    // The cancelled booking is kept as history next to the new one.
    expect(EventBooking::query()->where('user_id', $member->id)->pluck('status')->sort()->values()->all())
        ->toBe(['cancelled', 'confirmed'])
        ->and(AuditEvent::query()->where('event', 'booking.confirmed')->count())->toBe(2)
        ->and(AuditEvent::query()->where('event', 'booking.cancelled')->count())->toBe(1);
});

it('rejects a second active booking of the same member', function () {
    $event = bookableEvent();
    $member = User::factory()->create();

    book($event, $member)->assertCreated();
    book($event, $member)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_BOOKED');

    expect($event->bookings()->count())->toBe(1);
});

it('rejects bookings when the event is full', function () {
    $event = bookableEvent(['booking_capacity' => 1]);

    book($event, User::factory()->create())->assertCreated();
    book($event, User::factory()->create())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'BOOKING_FULL');

    expect($event->bookings()->where('status', 'confirmed')->count())->toBe(1);
});

it('does not overbook when the last seat is requested again and again', function () {
    // Sequential stand-in for the parallel CI check (scripts/booking_concurrency_check.sh).
    $event = bookableEvent(['booking_capacity' => 3]);
    $members = User::factory()->count(6)->create();
    $codes = $members->map(fn (User $m) => book($event, $m)->status())->all();

    expect(array_count_values($codes))->toBe([201 => 3, 409 => 3])
        ->and(app(BookingService::class)->confirmedCount($event))->toBe(3);
});

it('refuses bookings for drafts, cancelled, disabled, not yet open and closed events', function () {
    $member = User::factory()->create();

    $draft = bookableEvent(['status' => CalendarEvent::STATUS_DRAFT]);
    book($draft, $member)->assertNotFound();

    $cancelled = bookableEvent();
    $cancelled->cancel('颱風');
    book($cancelled, $member)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'event_cancelled');

    $disabled = bookableEvent(['booking_enabled' => false, 'booking_capacity' => null]);
    book($disabled, $member)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'disabled');

    $later = bookableEvent(['booking_opens_at' => now()->addDay()]);
    book($later, $member)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'not_open');
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$later->id}/booking")
        ->assertJsonPath('data.unavailable_reason', 'not_open');

    $closed = bookableEvent(['booking_closes_at' => now()->subMinute(), 'booking_opens_at' => now()->subDay()]);
    book($closed, $member)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'closed');

    $started = bookableEvent(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    book($started, $member)->assertStatus(409)->assertJsonPath('error.fields.reason.0', 'closed');

    expect(EventBooking::query()->count())->toBe(0);
});

it('requires login and a satisfied verification policy', function () {
    $event = bookableEvent();

    $this->postJson("/api/v1/events/{$event->id}/bookings")->assertUnauthorized();
    $this->getJson('/api/v1/me/bookings')->assertUnauthorized();

    $unverified = User::factory()->unverified()->create();
    book($event, $unverified)->assertForbidden()->assertJsonPath('error.code', 'VERIFICATION_REQUIRED');

    expect($event->bookings()->count())->toBe(0);
});

it('never lets a member see or cancel another member\'s booking', function () {
    $event = bookableEvent();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $bBooking = book($event, $b)->json('data.my_booking.id');

    $this->actingAs($a, 'sanctum')->postJson("/api/v1/me/bookings/{$bBooking}/cancel")
        ->assertNotFound();
    $this->actingAs($a, 'sanctum')->getJson('/api/v1/me/bookings')->assertJsonCount(0, 'data');

    expect(EventBooking::query()->findOrFail($bBooking)->status)->toBe('confirmed');
});

it('validates booking settings on the event', function () {
    expect(fn () => bookableEvent(['booking_capacity' => null]))->toThrow(ValidationException::class)
        ->and(fn () => bookableEvent(['booking_capacity' => 0]))->toThrow(ValidationException::class)
        ->and(fn () => bookableEvent(['booking_opens_at' => now()->addDays(2), 'booking_closes_at' => now()->addDay()]))->toThrow(ValidationException::class);
});

it('lets an admin enable bookings, see the list and cancel a booking', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    $this->actingAs($admin);
    $event = bookableEvent(['booking_enabled' => false, 'booking_capacity' => null]);

    Livewire::test(EditCalendarEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['booking_enabled' => true, 'booking_capacity' => 5])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($event->refresh()->booking_enabled)->toBeTrue()->and($event->booking_capacity)->toBe(5);

    $member = User::factory()->create(['name' => '預約會員']);
    $booking = app(BookingService::class)->book($event, $member);

    Livewire::test(ListCalendarEvents::class)
        ->assertSee('已預約 1 / 5（剩 4）');

    Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditCalendarEvent::class])
        ->assertCanSeeTableRecords([$booking])
        ->assertSee('預約會員')
        ->callTableAction('cancelBooking', $booking);

    expect($booking->refresh()->status)->toBe('cancelled')
        ->and($booking->cancelled_by)->toBe($admin->id)
        ->and(AuditEvent::query()->where('event', 'booking.cancelled')->firstOrFail()->details['by'])->toBe('admin');
});

it('keeps the booking admin behind events.manage', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = makeAdmin();
    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo('events.manage');
    $this->actingAs($admin->refresh());
    $event = bookableEvent();

    $this->get("/admin/calendar-events/{$event->id}/edit")->assertForbidden();

    // A member account never reaches the admin.
    $this->actingAs(User::factory()->create())->get('/admin/calendar-events')->assertForbidden();
});

it('enforces legacy cancellation ownership inside the service even when bypassing the API controller', function () {
    $event = bookableEvent();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $booking = app(BookingService::class)->book($event, $owner);

    try {
        app(BookingService::class)->cancel($booking, $stranger);
        test()->fail('An ordinary member must not cancel another member booking');
    } catch (\App\Support\\Api\\ApiException $exception) {
        expect($exception)->toBeInstanceOf(\App\Support\\Api\\ApiException::class);
    }

    expect($booking->refresh()->status)->toBe('confirmed');
});

it('permits the actual administrator to cancel a legacy booking through the service', function () {
    $event = bookableEvent();
    $owner = User::factory()->create();
    $admin = makeAdmin();
    $booking = app(BookingService::class)->book($event, $owner);

    app(BookingService::class)->cancel($booking, $admin);

    expect($booking->refresh()->status)->toBe('cancelled')
        ->and($booking->cancelled_by)->toBe($admin->id);
});

it('rejects a forged administrator flag passed directly to the booking engine', function () {
    $event = bookableEvent();
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $booking = app(BookingService::class)->book($event, $owner);

    try {
        app(\App\Services\\Booking\\BookingEngine::class)->cancelLegacy($booking, $stranger, true);
        test()->fail('A forged admin flag must not bypass ownership');
    } catch (\App\Support\\Api\\ApiException $exception) {
        expect($exception)->toBeInstanceOf(\App\Support\\Api\\ApiException::class);
    }

    expect($booking->refresh()->status)->toBe('confirmed');
});
