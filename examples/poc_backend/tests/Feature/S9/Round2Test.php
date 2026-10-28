<?php

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\EventAttendance;
use App\Models\EventBookingAttendee;
use App\Models\EventBookingGroup;
use App\Models\EventCategory;
use App\Models\MarketingCampaign;
use App\Models\MarketingPlacement;
use App\Models\MemberVerification;
use App\Models\Tag;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Models\VerificationType;
use App\Services\Analytics\LinkAnalytics;
use App\Services\Analytics\LinkReport;
use App\Services\Booking\BookingEngine;
use App\Services\Booking\BookingRequest;
use App\Services\Checkin\CheckinService;
use App\Services\Checkin\CheckinTokens;
use App\Services\Content\TagService;
use App\Services\Marketing\MarketingService;
use App\Services\Verification\VerificationService;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Second review round (docs/s9_compliance_matrix.md): high-risk permission,
// data-consistency and flow tests, and M11-D24-A (append-only audit, check-in
// personal data purged, no re-identification).

function r2Staff(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('checkin_staff', 'web'));

    return $user;
}

/** Open occurrence with three registered member attendees. */
function r2Occurrence(): array
{
    $category = EventCategory::query()->create(['name' => 'R2 開放', 'allow_proxy_booking' => true]);
    $event = s9Event(['audience' => CalendarEvent::AUDIENCE_PUBLIC, 'booking_capacity' => 10, 'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addHours(2), 'checkin_enabled' => true, 'checkout_enabled' => true], $category);
    $member = User::factory()->create(['name' => '會員甲乙丙', 'email' => 'member-r2@example.com']);
    groupBook($event, $member)->assertCreated();
    $family = User::factory()->create(['name' => '家人丁戊']);
    groupBook($event, $family)->assertCreated();
    $other = User::factory()->create(['name' => '會員己庚', 'email' => 'other-r2@example.com', 'phone_e164' => '+886912000111']);
    groupBook($event, $other)->assertCreated();
    $attendees = EventBookingAttendee::query()->where('calendar_event_id', $event->id)->orderBy('id')->get();

    return [$event, $member, $attendees, $other];
}

// ---- M11-D24-A / D28 ---------------------------------------------------------------

it('keeps the audit log append-only, purges check-in data and leaves no way to re-identify attendees', function () {
    [$event, $member, $attendees, $other] = r2Occurrence();
    $staff = r2Staff();
    $admin = makeAdmin();

    // Member self check-in, staff manual registered-member check-in, staff checkout, admin correction.
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin", ['attendee_id' => $attendees[0]->id, 'event_token' => app(CheckinTokens::class)->event($event->id)])->assertOk();
    $this->actingAs($staff, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", ['attendee_id' => $attendees[2]->id, 'contact' => '0912-000-111'])->assertOk();
    $this->actingAs($staff, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", ['attendee_id' => $attendees[2]->id, 'action' => 'checkout'])->assertOk();
    app(CheckinService::class)->adminSet($event, $attendees[1], EventAttendance::CHECKED_IN, $admin, '家人丁戊遲到補登 0912000111');

    $audits = AuditEvent::query()->where('event', 'like', 'checkin.%')->orderBy('id')->get();
    $before = $audits->map(fn (AuditEvent $a) => $a->getAttributes())->all();
    $allowedKeys = ['event_id', 'source', 'actor_role', 'before', 'after', 'late', 'reason_recorded', 'correction_id'];
    $personal = ['會員甲乙丙', '家人丁戊', '會員己庚', 'member-r2@example.com', 'other-r2@example.com', '912000111', '遲到補登'];

    expect($audits)->toHaveCount(4);
    foreach ($audits as $audit) {
        // Only de-identified facts; no attendee ids, contacts or free-text reasons.
        expect(array_diff(array_keys((array) $audit->details), $allowedKeys))->toBe([]);
        foreach ($personal as $needle) {
            expect(json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE))->not->toContain($needle);
        }
        // The actor is never the attendee: a member checking in is not named; staff and admins are (responsibility).
        expect($audit->actor_id === null || in_array($audit->actor_id, [$staff->id, $admin->id], true))->toBeTrue()
            ->and($audit->actor_id)->not->toBe($member->id);
    }
    expect($audits->pluck('actor_id')->all())->toBe([null, $staff->id, $staff->id, $admin->id]);

    // Retention passes.
    app(Settings::class)->put(['checkin_retention_days' => 30]);
    $this->travel(40)->days();
    $result = app(CheckinService::class)->purge();

    expect($result)->toMatchArray(['attendances_deleted' => 3, 'corrections_deleted' => 1])
        // Existing audit rows unchanged column by column.
        ->and(AuditEvent::query()->whereIn('id', $audits->pluck('id'))->orderBy('id')->get()->map(fn (AuditEvent $a) => $a->getAttributes())->all())->toBe($before);

    // No link from the audit rows back to a person any more.
    foreach ($audits as $audit) {
        expect(EventAttendance::query()->find($audit->subject_id))->toBeNull();
        if (isset($audit->details['correction_id'])) {
            expect(DB::table('event_attendance_corrections')->where('id', $audit->details['correction_id'])->exists())->toBeFalse();
        }
    }
    expect(DB::table('event_attendances')->count())->toBe(0)
        ->and(DB::table('event_attendance_corrections')->count())->toBe(0);

    // The purge itself is a new, separate audit event with its evidence.
    $purge = AuditEvent::query()->where('event', 'checkin.retention_purge')->sole();
    expect($purge->details)->toHaveKeys(['attendances_deleted', 'corrections_deleted', 'retention_days', 'cutoff'])
        ->and($purge->id)->toBeGreaterThan($audits->last()->id);
});

// ---- M11-D11 / D17 / D02 staff manual check-in ------------------------------------------

it('lets staff check in registered members and forbids ordinary members using staff endpoints', function () {
    [$event, $member, $attendees] = r2Occurrence();
    $staff = r2Staff();
    $post = fn (array $body, ?User $as = null) => $this->actingAs($as ?? $staff, 'sanctum')->postJson("/api/v1/events/{$event->id}/checkin/staff", $body);

    $post(['attendee_id' => $attendees[2]->id])->assertOk()->assertJsonPath('data.result', 'checked_in');
    $post(['attendee_id' => $attendees[1]->id])->assertOk();
    $post(['attendee_id' => $attendees[0]->id], $member)->assertStatus(403);

    $list = $this->actingAs($staff, 'sanctum')->getJson("/api/v1/events/{$event->id}/attendance")->assertOk();
    expect($list->getContent())->not->toContain('other-r2@example.com')->not->toContain('912000111');
});

// ---- Admin responsibility: permissions of the S9 admin screens ---------------------------

it('closes each S9 admin screen without its own permission', function (string $path, string $permission) {
    $admin = makeAdmin();
    $this->actingAs($admin, 'web')->get($path)->assertOk();

    Role::findByName(User::ADMIN_ROLE, 'web')->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($admin->fresh(), 'web')->get($path)->assertForbidden();
})->with([
    'marketing campaigns' => ['/admin/marketing-campaigns', 'marketing.manage'],
    'tags' => ['/admin/tags', 'tags.manage'],
    'verification requests' => ['/admin/verification-requests', 'verifications.review'],
    'check-in locations' => ['/admin/checkin-locations', 'checkin.manage'],
]);

// ---- M08-D14 admin actions are attributed --------------------------------------------------

it('attributes admin bookings and cancellations to the admin and tells the member', function () {
    $event = s9Event(['booking_capacity' => 5]);
    $member = User::factory()->create();
    $admin = makeAdmin();

    $group = app(BookingEngine::class)->book($event, new BookingRequest(
        booker: $member, contactName: $member->name, contactEmail: $member->email, contactPhone: null,
        attendees: [['name' => $member->name, 'member_user_id' => $member->id]], clientRequestId: (string) Str::uuid(), byAdmin: true, actor: $admin,
    ));
    app(BookingEngine::class)->cancelGroup($group, $admin, true, '主辦調整');

    expect(AuditEvent::query()->where('event', 'booking.confirmed')->sole()->actor_id)->toBe($admin->id)
        ->and(AuditEvent::query()->where('event', 'booking.group_cancelled')->sole()->actor_id)->toBe($admin->id)
        ->and($member->notifications()->latest()->first()->data['title'])->toBe('報名已取消');
});

// ---- M03-D09 several valid verifications of one type by default ----------------------------

it('allows several valid verifications of the same type by default', function () {
    $type = VerificationType::query()->create(['name' => '多重', 'requires_evidence' => false, 'required_approvals' => 1, 'is_active' => true]);
    $member = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole(Role::findOrCreate('verification_reviewer', 'web'));
    $service = app(VerificationService::class);

    foreach ([1, 2] as $ignored) {
        $request = $service->apply($member, $type->refresh(), null, []);
        $service->review($request, $reviewer, 'approve', null);
    }

    expect($type->refresh()->allow_multiple)->toBeTrue()
        ->and(MemberVerification::query()->valid()->where('user_id', $member->id)->count())->toBe(2)
        ->and(VerificationRequest::query()->where('status', 'approved')->count())->toBe(2);
});

// ---- M17 periods, source priority and option to tag share links ------------------------------

it('uses fixed report periods in the statistics time zone', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 17:00:00', 'UTC')); // 2026-11-11 01:00 Asia/Taipei
    $report = app(LinkReport::class);

    expect($report->range('today'))->toBe(['2026-11-11', '2026-11-11'])
        ->and($report->range('yesterday'))->toBe(['2026-11-10', '2026-11-10'])
        ->and($report->range('7d'))->toBe(['2026-11-05', '2026-11-11'])
        ->and($report->range('30d'))->toBe(['2026-10-13', '2026-11-11'])
        ->and($report->range('custom', '2026-11-01', '2026-11-03'))->toBe(['2026-11-01', '2026-11-03']);
    Carbon::setTestNow();
});

it('prefers a valid UTM source over the referrer and ignores malformed UTM', function () {
    $request = Request::create('/articles/1?utm_source=line&utm_campaign=Winter_2026', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.google.com/']);
    expect(LinkAnalytics::classify($request, 'web'))->toMatchArray(['source' => 'line', 'campaign' => 'winter_2026']);

    $bad = Request::create('/articles/1?utm_source=<script>', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.google.com/']);
    expect(LinkAnalytics::classify($bad, 'web')['source'])->toBe('search');
});

it('adds UTM parameters to a share link only when asked and only in a safe format', function () {
    config(['deeplinks.base_url' => 'https://tiandi.example']);
    $article = liveArticle();

    $this->getJson("/api/v1/content/article/{$article->id}/share")->assertJsonPath('data.url', "https://tiandi.example/articles/{$article->id}");
    $this->getJson("/api/v1/content/article/{$article->id}/share?utm_source=line&utm_medium=share&utm_campaign=winter")
        ->assertJsonPath('data.url', "https://tiandi.example/articles/{$article->id}?utm_source=line&utm_medium=share&utm_campaign=winter");
    $this->getJson("/api/v1/content/article/{$article->id}/share?utm_source=".urlencode('a b'))->assertStatus(422);
});

// ---- M16-D14 popular tags, M16-D30 / M15-D23 audited admin operations ------------------------

it('sorts popular tags by public usage and audits tag and campaign operations', function () {
    $tags = app(TagService::class);
    $admin = makeAdmin();
    [$few, $many] = [$tags->create('少', null, 1, $admin), $tags->create('多', null, 2, $admin)];
    $a = liveArticle();
    $b = liveArticle();
    $tags->sync('article', $a->id, [$few->id, $many->id], $admin);
    $tags->sync('article', $b->id, [$many->id], $admin);

    expect(collect($this->getJson('/api/v1/tags?sort=popular')->json('data'))->pluck('name')->all())->toBe(['多', '少'])
        ->and(collect($this->getJson('/api/v1/tags')->json('data'))->pluck('name')->all())->toBe(['少', '多']);

    $tags->merge($few, $many, $admin);
    $tags->delete([$many->id], $admin);

    $campaign = MarketingCampaign::query()->create(['title' => '推廣', 'button_text' => '看', 'target_type' => 'url', 'target_url' => 'https://example.com',
        'status' => 'draft', 'priority' => 0, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
    $campaign->placements()->sync([MarketingPlacement::query()->where('code', 'home_banner')->value('id')]);
    $marketing = app(MarketingService::class);
    $marketing->publish($campaign, $admin);
    expect($this->getJson('/api/v1/marketing/placements/home_banner')->json('data'))->toHaveCount(1);
    $marketing->disable($campaign, $admin); // M15-D20: stops at once
    expect($this->getJson('/api/v1/marketing/placements/home_banner')->json('data'))->toHaveCount(0);
    $marketing->delete($marketing->duplicate($campaign, $admin), $admin);

    $events = AuditEvent::query()->whereIn('actor_id', [$admin->id])->pluck('event')->all();
    expect($events)->toContain('tag.created', 'tag.content_synced', 'tag.merged', 'tag.deleted',
        'marketing.published', 'marketing.disabled', 'marketing.duplicated', 'marketing.deleted');
    expect(Tag::query()->count())->toBe(0);
});

// ---- M14-D28 preview image ---------------------------------------------------------------

it('uses the article cover for the preview image and the App default otherwise', function () {
    config(['deeplinks.default_image' => 'https://tiandi.example/og-default.png']);
    $withCover = liveArticle(['cover_url' => 'https://cdn.example/cover.jpg']);
    $plain = liveArticle();

    $this->get("/articles/{$withCover->id}")->assertSee('<meta property="og:image" content="https://cdn.example/cover.jpg">', false);
    $this->get("/articles/{$plain->id}")->assertSee('<meta property="og:image" content="https://tiandi.example/og-default.png">', false);
});

// ---- M08 data consistency: S6 and S9 views of the same booking ------------------------------

it('keeps the S6 booking row and the S9 ledger consistent through cancel and promotion', function () {
    $event = s9Event(['booking_capacity' => 1]);
    [$first, $waiting] = [User::factory()->create(), User::factory()->create()];

    $this->actingAs($first, 'sanctum')->postJson("/api/v1/events/{$event->id}/bookings")->assertCreated();
    groupBook($event, $waiting)->assertJsonPath('data.status', 'waitlisted');
    $s9 = EventBookingGroup::query()->where('booker_user_id', $first->id)->sole();
    $this->actingAs($first, 'sanctum')->postJson("/api/v1/me/booking-groups/{$s9->id}/cancel")->assertOk();

    // Cancelling through the S9 API also cancels the S6 row; the waiting member moves up.
    expect(DB::table('event_bookings')->where('user_id', $first->id)->value('status'))->toBe('cancelled')
        ->and(EventBookingGroup::query()->where('booker_user_id', $waiting->id)->value('status'))->toBe('confirmed')
        ->and(seats($event))->toBe(1);
    $this->actingAs($first, 'sanctum')->getJson("/api/v1/events/{$event->id}/booking")->assertJsonPath('data.my_booking', null);
});
