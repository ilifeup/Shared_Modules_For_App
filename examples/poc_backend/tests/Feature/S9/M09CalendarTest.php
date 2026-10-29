<?php

use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\EventSeries;
use App\Models\MembershipLevel;
use App\Models\PrivateCalendarEvent;
use App\Models\User;
use App\Services\Calendar\EventSeriesService;
use App\Services\MembershipService;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function weeklySeries(array $rule = [], array $template = []): EventSeries
{
    return app(EventSeriesService::class)->create(array_merge([
        'frequency' => 'weekly', 'timezone' => 'Asia/Taipei', 'local_start_time' => '19:30', 'duration_minutes' => 90,
        'starts_on' => now()->addDays(1)->toDateString(), 'until_on' => now()->addDays(1 + 7 * 4)->toDateString(),
    ], $rule), array_merge([
        'title' => '每週共修', 'status' => CalendarEvent::STATUS_PUBLISHED, 'audience' => CalendarEvent::AUDIENCE_MEMBERS,
        'booking_enabled' => true, 'booking_capacity' => 10, 'booking_max_seats' => 3, 'location' => '道場', 'floor' => '3F', 'studio' => null,
        'event_category_id' => EventCategory::query()->firstOrCreate(['name' => '一般'], ['allow_proxy_booking' => true, 'allow_guest_booking' => true])->id,
    ], $template), makeAdmin());
}

// G04 / G17 — weekly / monthly expansion in the series zone (DST-safe wall clock).
it('generates weekly occurrences as real calendar events with stable ids', function () {
    $series = weeklySeries();
    $events = $series->occurrences()->get();

    expect($events)->toHaveCount(5)
        ->and($events->first()->starts_at->timezone('Asia/Taipei')->format('H:i'))->toBe('19:30')
        ->and($events->first()->floor)->toBe('3F')
        ->and($events->first()->studio)->toBeNull()
        ->and($events->pluck('event_series_id')->unique()->all())->toBe([$series->id]);

    // Generating the same rule again never duplicates (UNIQUE series + original start).
    expect(fn () => CalendarEvent::query()->create(['title' => 'x', 'starts_at' => $events[0]->starts_at, 'ends_at' => $events[0]->ends_at])->forceFill([
        'event_series_id' => $series->id, 'series_original_start_at' => $events[0]->series_original_start_at,
    ])->save())->toThrow(QueryException::class);
});

it('keeps local time across DST and skips months without the day', function () {
    $service = app(EventSeriesService::class);
    $ny = $service->occurrenceStarts('weekly', 'America/New_York', '10:00', '2026-10-25', '2026-11-15');
    expect(collect($ny)->map(fn ($s) => $s->copy()->timezone('America/New_York')->format('H:i'))->unique()->values()->all())->toBe(['10:00'])
        ->and($ny[0]->format('H'))->not->toBe($ny[2]->format('H')); // UTC hour shifts at the DST change

    $monthly = $service->occurrenceStarts('monthly', 'Asia/Taipei', '09:00', '2027-01-31', '2027-05-31');
    expect(collect($monthly)->map(fn ($s) => $s->copy()->timezone('Asia/Taipei')->format('m-d'))->all())->toBe(['01-31', '03-31', '05-31']);

    expect(fn () => $service->occurrenceStarts('daily', 'Asia/Taipei', '09:00', '2027-01-01', '2027-02-01'))->toThrow(ValidationException::class);
});

// G05 / G06 — single / future / all with S9-L01 history protection.
it('applies future and all changes only to not-started, non-exception occurrences', function () {
    $series = weeklySeries();
    [$first, $second, $third, $fourth] = $series->occurrences()->get()->all();
    $admin = makeAdmin();
    $service = app(EventSeriesService::class);

    // Single edit: becomes an exception.
    $service->update($third, 'single', ['studio' => 'B 教室'], $admin);
    expect($third->refresh()->is_series_exception)->toBeTrue();

    // The first one already started (history).
    $first->forceFill(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()])->saveQuietly();

    $result = $service->update($second, 'all', ['location' => '新道場'], $admin);
    expect($result['skipped_started'])->toContain($first->id)
        ->and($result['skipped_exceptions'])->toContain($third->id)
        ->and($first->refresh()->location)->toBe('道場')
        ->and($third->refresh()->location)->toBe('道場')
        ->and($second->refresh()->location)->toBe('新道場')
        ->and($fourth->refresh()->location)->toBe('新道場')
        ->and($fourth->id)->toBe($series->occurrences()->get()[3]->id); // ids never change
});

// L02 / M08-D11 — reschedule keeps bookings, notifies, recomputes relative deadlines, flags fixed ones.
it('reschedules future occurrences, keeps bookings and flags fixed deadlines', function () {
    $series = weeklySeries([], ['booking_close_offset_minutes' => 60]);
    [$a, $b] = $series->occurrences()->get()->all();
    $member = User::factory()->create();
    groupBook($b, $member)->assertCreated();
    $b->forceFill(['cancel_closes_at' => $b->starts_at->copy()->subDay()])->save(); // fixed deadline set by an admin
    $fixed = $b->cancel_closes_at->copy();

    $result = app(EventSeriesService::class)->update($a, 'future', ['local_start_time' => '20:00'], makeAdmin());

    $b->refresh();
    expect($b->starts_at->timezone('Asia/Taipei')->format('H:i'))->toBe('20:00')
        ->and($b->bookingClosesAt()->equalTo($b->starts_at->copy()->subHour()))->toBeTrue()
        ->and($b->cancel_closes_at->equalTo($fixed))->toBeTrue()
        ->and($result['fixed_deadlines'])->toContain($b->id)
        ->and(EventBookingGroup::query()->where('calendar_event_id', $b->id)->value('status'))->toBe('confirmed')
        ->and($member->notifications()->first()->data['title'])->toContain('活動異動')
        ->and(AuditEvent::query()->where('event', 'event.updated')->latest('id')->first()->details['fixed_deadline_kept'])->toContain($b->id);
});

it('cancels this and future occurrences without touching started ones', function () {
    $series = weeklySeries();
    $events = $series->occurrences()->get();
    $events[0]->forceFill(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()])->saveQuietly();
    $member = User::factory()->create();
    groupBook($events[2], $member);

    $ids = app(EventSeriesService::class)->cancel($events[0], 'all', '停課', makeAdmin());

    expect($ids)->not->toContain($events[0]->id)
        ->and($events[0]->refresh()->status)->toBe('published')
        ->and($events[2]->refresh()->status)->toBe('cancelled')
        ->and(EventBookingGroup::query()->value('status'))->toBe('event_cancelled');
});

// G02 / G03 — member calendar: public + member events, my bookings, category colours.
it('returns my calendar with category colours, venue and my booking state', function () {
    $category = EventCategory::query()->create(['name' => '法會', 'color' => '#AA3300']);
    $mine = s9Event(['title' => '我報名的', 'floor' => '2F'], $category);
    $other = s9Event(['title' => '別的', 'starts_at' => now()->addDays(8), 'ends_at' => now()->addDays(8)->addHour()]);
    s9Event(['title' => '草稿', 'status' => CalendarEvent::STATUS_DRAFT]);
    $member = User::factory()->create();
    groupBook($mine, $member);

    $response = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/calendar?from='.now()->toDateString().'&to='.now()->addDays(30)->toDateString())
        ->assertOk();
    $events = collect($response->json('data.events'))->keyBy('title');
    expect($events->keys()->sort()->values()->all())->toBe(['別的', '我報名的'])
        ->and($events['我報名的']['my_booking_status'])->toBe('confirmed')
        ->and($events['我報名的']['category']['color'])->toBe('#AA3300')
        ->and($events['我報名的']['floor'])->toBe('2F')
        ->and($events['別的']['my_booking_status'])->toBeNull()
        ->and($response->json('data.vip'))->toBeFalse();

    // S5 endpoints keep their shape and gain only additive fields.
    $this->actingAs($member, 'sanctum')->getJson("/api/v1/events/{$other->id}")->assertOk()
        ->assertJsonPath('data.id', (string) $other->id)->assertJsonPath('data.timezone', 'Asia/Taipei');

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/calendar?from=2026-01-01&to=2026-06-01')->assertStatus(422);
});

// G07 / G08 — VIP private entries: CRUD while VIP, read-only (no delete) afterwards.
it('allows private entries only for the configured VIP levels and keeps them read-only after', function () {
    $member = User::factory()->create();
    $vip = MembershipLevel::query()->where('code', 'vip')->firstOrFail();
    $start = now()->addDays(2)->setTime(10, 0);

    $create = fn () => $this->actingAs($member, 'sanctum')->postJson('/api/v1/me/private-events', [
        'title' => '私人靜坐', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(),
        'timezone' => 'Asia/Taipei', 'recurrence' => 'weekly', 'recurrence_until' => $start->copy()->addWeeks(3)->toDateString(),
    ]);

    $create()->assertStatus(403)->assertJsonPath('error.code', 'VIP_REQUIRED');

    app(Settings::class)->put(['calendar_vip_level_codes' => 'vip']);
    $assignment = app(MembershipService::class)->assign($member, $vip, now()->subDay(), now()->addDays(30), null, makeAdmin());
    $id = $create()->assertCreated()->json('data.id');

    $calendar = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/calendar?from='.now()->toDateString().'&to='.now()->addDays(40)->toDateString())->json('data');
    expect($calendar['private_events'])->toHaveCount(4)->and($calendar['private_events'][0]['editable'])->toBeTrue();

    $this->actingAs($member, 'sanctum')->patchJson("/api/v1/me/private-events/{$id}", ['title' => '改名'])->assertOk()->assertJsonPath('data.title', '改名');

    // VIP ends: entries stay visible, but no create / update / delete (D08, D14).
    $assignment->forceFill(['ends_at' => now()->subMinute()])->save();
    $calendar = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/calendar?from='.now()->toDateString().'&to='.now()->addDays(40)->toDateString())->json('data');
    expect($calendar['vip'])->toBeFalse()->and($calendar['private_events'][0]['editable'])->toBeFalse();
    $this->actingAs($member, 'sanctum')->patchJson("/api/v1/me/private-events/{$id}", ['title' => 'x'])->assertStatus(403);
    $this->actingAs($member, 'sanctum')->deleteJson("/api/v1/me/private-events/{$id}")->assertStatus(403);
    expect(PrivateCalendarEvent::query()->count())->toBe(1);

    // Other members never see it.
    $this->actingAs(User::factory()->create(), 'sanctum')->patchJson("/api/v1/me/private-events/{$id}", ['title' => 'x'])->assertNotFound();
});

it('lists event categories publicly', function () {
    EventCategory::query()->create(['name' => '講座', 'color' => '#112233']);
    $this->getJson('/api/v1/event-categories')->assertOk()->assertJsonFragment(['name' => '講座', 'color' => '#112233']);
});

it('keeps an occurrence edited in the admin as an exception and notifies bookings', function () {
    $series = weeklySeries();
    $event = $series->occurrences()->first();
    $member = User::factory()->create();
    groupBook($event, $member);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeAdmin(), 'web');
    Livewire::test(EditCalendarEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['studio' => 'C 室'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->refresh()->is_series_exception)->toBeTrue()
        ->and($event->studio)->toBe('C 室')
        ->and($member->notifications()->count())->toBe(1);
});
