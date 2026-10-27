<?php

use App\Models\CalendarEvent;
use App\Models\CheckinLocation;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\MemberVerification;
use App\Models\User;
use App\Models\VerificationType;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Verification\VerificationService;
use App\Support\Api\ApiException;
use Illuminate\Support\Str;

// Fixes from the 2026-10-25 review (docs/s9_compliance_matrix.md).

// M08-D05: the seat limit is per person and occurrence, not per request.
it('rejects multiple attendees and duplicate active bookings for one member', function () {
    $event = s9Event(['booking_capacity' => 20]);
    $member = User::factory()->create();

    groupBook($event, $member, ['家人'])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    groupBook($event, $member)->assertCreated()
        ->assertJsonPath('data.seat_count', 1);
    groupBook($event, $member)->assertStatus(409)
        ->assertJsonPath('error.code', 'ALREADY_BOOKED');

    expect(EventBookingGroup::query()->where('booker_user_id', $member->id)
        ->where('status', 'confirmed')->sum('seat_count'))->toEqual(1);
});

it('rejects guest contacts even for a public event and direct engine calls', function () {
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC]);
    expect(fn () => app(BookingEngine::class)->book($event, new BookingRequest(
        booker: null, contactName: '訪客', contactEmail: 'guest@example.com', contactPhone: null,
        attendees: [['name' => '訪客', 'member_user_id' => null]], clientRequestId: (string) Str::uuid(),
    )))->toThrow(ApiException::class, '正式會員');
    expect(EventBookingAttendee::query()->where('calendar_event_id', $event->id)->count())->toBe(0);
});

// M11-D20: the allowed distance is the configured radius; an imprecise fix is refused.
it('refuses GPS check-in when the fix is less precise than the radius', function () {
    $event = s9Event(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHours(2), 'checkin_enabled' => true,
        'checkin_gps_required' => true, 'checkin_gps_radius_m' => 100]);
    $member = User::factory()->create();
    groupBook($event, $member)->assertCreated();
    $attendee = EventBookingAttendee::query()->where('calendar_event_id', $event->id)->sole();
    $location = CheckinLocation::query()->create(['name' => '大殿', 'latitude' => 25.0330, 'longitude' => 121.5654]);
    $location->events()->attach($event->id);

    $post = fn (array $gps) => $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin",
        ['attendee_ids' => [$attendee->id], 'location_token' => $location->token] + $gps);

    // 150 m away with a claimed 100 m accuracy used to pass (radius + accuracy); not any more.
    $post(['lat' => 25.03435, 'lng' => 121.5654, 'accuracy' => 100])->assertStatus(422)->assertJsonPath('error.code', 'GPS_OUT_OF_RANGE');
    $post(['lat' => 25.0331, 'lng' => 121.5654, 'accuracy' => 500])->assertStatus(422)->assertJsonPath('error.code', 'GPS_INACCURATE');
    $post(['lat' => 25.0331, 'lng' => 121.5654, 'accuracy' => 30])->assertOk()->assertJsonPath('data.0.result', 'checked_in');
});

// M03-D07: only authorised admins can revoke, also when called outside Filament.
it('refuses to revoke a verification without the review permission', function () {
    $type = VerificationType::query()->create(['name' => '志工', 'requires_evidence' => false, 'required_approvals' => 1, 'allow_multiple' => true, 'revoke_notify' => 'none', 'is_active' => true]);
    $badge = MemberVerification::query()->create(['user_id' => User::factory()->create()->id, 'verification_type_id' => $type->id, 'granted_at' => now(), 'is_public' => true]);

    expect(fn () => app(VerificationService::class)->revoke($badge, User::factory()->create(), '試圖撤銷'))->toThrow(ApiException::class);
    expect($badge->refresh()->revoked_at)->toBeNull();

    app(VerificationService::class)->revoke($badge, makeAdmin(), '資格不符');
    expect($badge->refresh()->revoked_at)->not->toBeNull();
});
