<?php

use App\Models\AuditEvent;
use App\Models\EventBookingGroup;
use App\Models\User;
use App\Services\Booking\BookingEngine;
use App\Services\Course\CourseEnrollmentService;
use App\Services\Payment\CashInvoiceService;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

// PR #53 batch A02-A08: whole-term cancellation, derived-session release,
// FIFO, cancellation vs. administrator refund, unpaid cancellation vs. cash,
// revoked authority and idempotent retries. Real-parallel MySQL checks live in
// scripts/course_term_concurrency_check.sh.

/** A paid term (NT$100) whose single session starts in 30 days (cutoff 10 days). */
function ctcTerm(int $capacity = 1, int $waitlist = 5): int
{
    $term = DB::table('course_terms')->insertGetId([
        'title' => 'Cancellation term', 'price_amount' => 100,
        'enrollment_capacity' => $capacity, 'waitlist_capacity' => $waitlist,
        'cancel_cutoff_days' => 10, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $event = s9Event(['starts_at' => now()->addDays(30), 'ends_at' => now()->addDays(30)->addHours(2),
        'booking_capacity' => 20]);
    DB::table('course_sessions')->insert([
        'course_term_id' => $term, 'calendar_event_id' => $event->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $term;
}

function ctcSession(int $term): int
{
    return (int) DB::table('course_sessions')->where('course_term_id', $term)->value('calendar_event_id');
}

function ctcInvoice(int $enrollmentId): object
{
    return DB::table('invoices')->where('booking_type', '02')->where('booking_id', $enrollmentId)->first();
}

function ctcDerivedGroup(int $enrollmentId, int $eventId): EventBookingGroup
{
    return EventBookingGroup::query()->where('client_request_id', (string) Uuid::uuid5(
        Uuid::NAMESPACE_URL, 'course-enrollment:'.$enrollmentId.':event:'.$eventId,
    ))->firstOrFail();
}

function ctcStatus(int $enrollmentId): string
{
    return (string) DB::table('course_enrollments')->where('id', $enrollmentId)->value('status');
}

function ctcFamily(User $manager): User
{
    $family = User::factory()->create(['verified_status' => 2, 'FamilyUser' => true]);
    DB::table('family_memberships')->insert([
        'manager_user_id' => $manager->id, 'member_user_id' => $family->id,
        'status' => 'active', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $family;
}

/** Every INSERT/UPDATE on $table fails like a database error until switched off. */
function ctcFailWrites(string $table): Closure
{
    $active = true;
    DB::connection()->beforeExecuting(function (string $query) use ($table, &$active): void {
        if ($active && preg_match('/^\s*(insert\s+into|update)\s+[`"]?'.preg_quote($table, '/').'[`"]?\s/i', $query) === 1) {
            throw new QueryException(DB::getDefaultConnection(), $query, [], new RuntimeException("injected failure: {$table}"));
        }
    });

    return function () use (&$active): void {
        $active = false;
    };
}

it('cancels an unpaid term once, releases the session, promotes the FIFO head and replays retries', function () {
    $term = ctcTerm();
    $holder = User::factory()->create();
    $next = User::factory()->create();
    $service = app(CourseEnrollmentService::class);
    $first = $service->enroll($term, $holder, $holder);
    $second = $service->enroll($term, $next, $next);
    expect($first['status'])->toBe('pending_payment')->and($second['status'])->toBe('waitlisted');

    $result = $service->cancel($first['id'], $holder);
    expect($result)->toBe(['id' => $first['id'], 'status' => 'cancelled', 'replayed' => false])
        ->and((int) ctcInvoice($first['id'])->invoice_status)->toBe(2)
        ->and(ctcDerivedGroup($first['id'], ctcSession($term))->status)->toBe(EventBookingGroup::STATUS_CANCELLED)
        ->and(ctcStatus($second['id']))->toBe('pending_payment')
        ->and((int) ctcInvoice($second['id'])->invoice_status)->toBe(0);

    // A retry (double tap / timeout) changes nothing: no second release, promotion or audit.
    $before = [DB::table('invoices')->count(), DB::table('event_booking_groups')->count(), AuditEvent::query()->count()];
    expect($service->cancel($first['id'], $holder))->toBe(['id' => $first['id'], 'status' => 'cancelled', 'replayed' => true])
        ->and([DB::table('invoices')->count(), DB::table('event_booking_groups')->count(), AuditEvent::query()->count()])->toBe($before)
        ->and(AuditEvent::query()->where('event', 'course.enrollment_cancelled')->where('subject_id', $first['id'])->count())->toBe(1);

    // The HTTP retry gets the same result.
    $this->actingAs($holder, 'sanctum')->postJson("/api/v1/me/course-enrollments/{$first['id']}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.replayed', true);
});

it('refuses cash for a cancelled unpaid term and keeps a paid cancellation for a separate manual refund', function () {
    $admin = makeAdmin();
    $service = app(CourseEnrollmentService::class);
    $cash = app(CashInvoiceService::class);

    // Unpaid: cancellation wins, the invoice is cancelled and cash is refused afterwards.
    $unpaidTerm = ctcTerm();
    $unpaidMember = User::factory()->create();
    $unpaid = $service->enroll($unpaidTerm, $unpaidMember, $unpaidMember);
    $service->cancel($unpaid['id'], $unpaidMember);
    expect(fn () => $cash->confirmCash((int) ctcInvoice($unpaid['id'])->id, $admin))->toThrow(ValidationException::class);
    expect(DB::table('payment_transactions')->where('invoice_id', ctcInvoice($unpaid['id'])->id)->count())->toBe(0);

    // Paid: cancellation keeps the invoice paid; no refund is written automatically.
    $paidTerm = ctcTerm();
    $member = User::factory()->create();
    $paid = $service->enroll($paidTerm, $member, $member);
    $invoiceId = (int) ctcInvoice($paid['id'])->id;
    $cash->confirmCash($invoiceId, $admin);
    $service->cancel($paid['id'], $member);
    expect((int) ctcInvoice($paid['id'])->invoice_status)->toBe(1)
        ->and(DB::table('payment_transactions')->where('invoice_id', $invoiceId)->where('transaction_amount', '<', 0)->count())->toBe(0)
        ->and(DB::table('invoice_refund_audits')->where('invoice_id', $invoiceId)->count())->toBe(0);

    // A PaidCheck replay reports the stored (cancelled) enrollment, not "confirmed".
    expect($service->paidCheck($paid['id'], $admin))->toMatchArray(['status' => 'cancelled', 'replayed' => true]);

    // The administrator refunds separately, exactly once; the cancellation is untouched.
    expect($cash->recordManualRefund($invoiceId, $admin, '現金退還'))->toBe(['id' => $invoiceId, 'status' => 5, 'replayed' => false])
        ->and($cash->recordManualRefund($invoiceId, $admin, '重送'))->toBe(['id' => $invoiceId, 'status' => 5, 'replayed' => true])
        ->and(DB::table('payment_transactions')->where('invoice_id', $invoiceId)->where('transaction_amount', '<', 0)->count())->toBe(1)
        ->and(ctcStatus($paid['id']))->toBe('cancelled');
});

it('refuses a refund for a mismatched invoice or one without its cash collection', function () {
    $admin = makeAdmin();
    $member = User::factory()->create();
    $term = ctcTerm();
    $service = app(CourseEnrollmentService::class);
    $entry = $service->enroll($term, $member, $member);
    $invoiceId = (int) ctcInvoice($entry['id'])->id;
    app(CashInvoiceService::class)->confirmCash($invoiceId, $admin);
    $service->cancel($entry['id'], $member);

    DB::table('invoices')->where('id', $invoiceId)->update(['member_id' => User::factory()->create()->id]);
    expect(fn () => app(CashInvoiceService::class)->recordManualRefund($invoiceId, $admin, 'x'))->toThrow(ValidationException::class);
    DB::table('invoices')->where('id', $invoiceId)->update(['member_id' => $member->id]);

    DB::table('payment_transactions')->where('invoice_id', $invoiceId)->update(['transaction_status' => 3]);
    expect(fn () => app(CashInvoiceService::class)->recordManualRefund($invoiceId, $admin, 'x'))->toThrow(ValidationException::class)
        ->and((int) ctcInvoice($entry['id'])->invoice_status)->toBe(1)
        ->and(DB::table('invoice_refund_audits')->count())->toBe(0);
});

it('rolls back the whole cancellation when a derived session cannot be released', function () {
    $term = ctcTerm();
    $holder = User::factory()->create();
    $next = User::factory()->create();
    $service = app(CourseEnrollmentService::class);
    $first = $service->enroll($term, $holder, $holder);
    $second = $service->enroll($term, $next, $next);
    $audits = AuditEvent::query()->count();

    $off = ctcFailWrites('event_booking_groups');
    expect(fn () => $service->cancel($first['id'], $holder))->toThrow(QueryException::class, 'injected failure');
    $off();

    expect(ctcStatus($first['id']))->toBe('pending_payment')
        ->and((int) ctcInvoice($first['id'])->invoice_status)->toBe(0)
        ->and(ctcDerivedGroup($first['id'], ctcSession($term))->status)->toBe(EventBookingGroup::STATUS_CONFIRMED)
        ->and(ctcStatus($second['id']))->toBe('waitlisted')
        ->and(ctcInvoice($second['id']))->toBeNull()
        ->and(AuditEvent::query()->count())->toBe($audits);

    // After the fault the same request succeeds normally.
    expect($service->cancel($first['id'], $holder)['replayed'])->toBeFalse()
        ->and(ctcStatus($second['id']))->toBe('pending_payment');
});

it('never cancels a derived session through the event flow and never offers it in the app', function () {
    $term = ctcTerm(2);
    $member = User::factory()->create();
    $entry = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
    $group = ctcDerivedGroup($entry['id'], ctcSession($term));

    foreach ([[$member, false, null], [makeAdmin(), true, '管理員改動'], [$member, true, 'course_enrollment_cancelled']] as [$actor, $byAdmin, $reason]) {
        try {
            app(BookingEngine::class)->cancelGroup($group, $actor, $byAdmin, $reason);
            $this->fail('A derived session must not be cancelled outside the whole-term flow.');
        } catch (ApiException $e) {
            expect($e->errorCode)->toBe('SERIES_BOOKING_MANAGED_BY_PARENT');
        }
    }
    expect($group->refresh()->status)->toBe(EventBookingGroup::STATUS_CONFIRMED)
        ->and(ctcStatus($entry['id']))->toBe('pending_payment');

    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/booking-groups')->assertOk()
        ->assertJsonPath('data.0.id', (string) $group->id)
        ->assertJsonPath('data.0.can_cancel', false);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/booking-groups/{$group->id}/cancel")
        ->assertStatus(409)->assertJsonPath('error.code', 'SERIES_BOOKING_MANAGED_BY_PARENT');
});

it('keeps the FIFO head waiting instead of promoting it onto an unavailable session', function () {
    $term = ctcTerm();
    $holder = User::factory()->create();
    $next = User::factory()->create();
    $service = app(CourseEnrollmentService::class);
    $first = $service->enroll($term, $holder, $holder);
    $second = $service->enroll($term, $next, $next);
    // Administrator data-integrity case: the session was disabled after admission.
    DB::table('calendar_events')->where('id', ctcSession($term))->update(['booking_enabled' => false]);

    // The member's cancellation still succeeds; the head keeps its place, no invoice, no seat.
    expect($service->cancel($first['id'], $holder)['status'])->toBe('cancelled')
        ->and(ctcStatus($second['id']))->toBe('waitlisted')
        ->and(ctcInvoice($second['id']))->toBeNull()
        ->and(DB::table('event_booking_attendees')->where('member_user_id', $next->id)->count())->toBe(0);

    // Once the session is valid again the next FIFO fill promotes exactly that head.
    DB::table('calendar_events')->where('id', ctcSession($term))->update(['booking_enabled' => true]);
    expect(app(CashInvoiceService::class)->fillCourseWaitlistAfterCapacityChange($term))->toBe(1)
        ->and(ctcStatus($second['id']))->toBe('pending_payment');
});

it('expires an unpaid term, fills FIFO and treats a later cancel as a replay', function () {
    $term = ctcTerm();
    $holder = User::factory()->create();
    $next = User::factory()->create();
    $service = app(CourseEnrollmentService::class);
    $first = $service->enroll($term, $holder, $holder);
    $second = $service->enroll($term, $next, $next);

    $this->travel(39)->hours();
    expect(app(CashInvoiceService::class)->expireDue())->toBeGreaterThanOrEqual(1)
        ->and(ctcStatus($first['id']))->toBe('cancelled')
        ->and((int) ctcInvoice($first['id'])->invoice_status)->toBe(3)
        ->and(ctcStatus($second['id']))->toBe('pending_payment')
        ->and($service->cancel($first['id'], $holder)['replayed'])->toBeTrue()
        ->and((int) ctcInvoice($first['id'])->invoice_status)->toBe(3);
});

it('replays waitlist withdrawal and refuses it for a seat holder', function () {
    $term = ctcTerm();
    $holder = User::factory()->create();
    $waiter = User::factory()->create();
    $service = app(CourseEnrollmentService::class);
    $first = $service->enroll($term, $holder, $holder);
    $second = $service->enroll($term, $waiter, $waiter);

    expect($service->cancelWaitlist($second['id'], $waiter))->toBe(['id' => $second['id'], 'status' => 'cancelled', 'replayed' => false])
        ->and($service->cancelWaitlist($second['id'], $waiter))->toBe(['id' => $second['id'], 'status' => 'cancelled', 'replayed' => true])
        ->and(fn () => $service->cancelWaitlist($first['id'], $holder))->toThrow(ValidationException::class)
        ->and(ctcStatus($first['id']))->toBe('pending_payment');
});

it('returns the same place for an enrollment retry without a second seat or invoice', function () {
    $term = ctcTerm();
    $member = User::factory()->create();
    $first = $this->actingAs($member, 'sanctum')->postJson("/api/v1/course-terms/{$term}/enrollments")
        ->assertCreated()->assertJsonPath('data.replayed', false)->json('data');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/course-terms/{$term}/enrollments")
        ->assertOk()->assertJsonPath('data.id', $first['id'])->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.status', 'pending_payment');

    expect(DB::table('course_enrollments')->where('course_term_id', $term)->count())->toBe(1)
        ->and(DB::table('invoices')->where('booking_type', '02')->where('product_id', $term)->count())->toBe(1)
        ->and(DB::table('event_booking_attendees')->where('member_user_id', $member->id)->count())->toBe(1);

    // After cancellation the same member cannot silently re-enter through a "retry".
    app(CourseEnrollmentService::class)->cancel($first['id'], $member);
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/course-terms/{$term}/enrollments")->assertStatus(422);
});

it('applies revoked family delegation and qualification even through stale user models', function () {
    $term = ctcTerm(2);
    $manager = User::factory()->create();
    $family = ctcFamily($manager);
    $service = app(CourseEnrollmentService::class);
    $entry = $service->enroll($term, $manager, $family);

    // Administrator ends the delegation (data change; the app has no unlink feature).
    DB::table('family_memberships')->where('member_user_id', $family->id)->update(['status' => 'ended', 'ended_at' => now()]);
    expect(fn () => $service->cancel($entry['id'], $manager))->toThrow(ValidationException::class)
        ->and(fn () => $service->enroll($term, $manager, $family))->toThrow(ValidationException::class)
        ->and(ctcStatus($entry['id']))->toBe('pending_payment');

    // Qualification revoked in the database while the caller still holds an old model.
    $self = User::factory()->create();
    $own = $service->enroll($term, $self, $self);
    $stale = User::query()->findOrFail($self->id);
    DB::table('users')->where('id', $self->id)->update(['verified_status' => 0]);
    expect((int) $stale->verified_status)->toBe(2)
        ->and(app(BookingEngine::class)->canManageFamily($stale, $stale, true))->toBeFalse()
        ->and(fn () => $service->cancel($own['id'], $stale))->toThrow(ValidationException::class)
        ->and(ctcStatus($own['id']))->toBe('pending_payment');

    // History stays visible to the original booker (FAST-DEVELOP §13).
    $this->actingAs($manager, 'sanctum')->getJson('/api/v1/me/course-enrollments')
        ->assertOk()->assertJsonPath('data.0.id', $entry['id']);
});

it('cancels an unpaid single event booking and refuses its cash afterwards', function () {
    $event = s9Event(['booking_capacity' => 2, 'price_amount' => 100, 'booking_closes_at' => now()->addDays(5)]);
    $member = User::factory()->create();
    $groupId = (int) groupBook($event, $member)->assertCreated()->json('data.id');
    $invoiceId = (int) DB::table('invoices')->where('booking_type', '01')->where('booking_id', $groupId)->value('id');

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/booking-groups/{$groupId}/cancel")->assertOk();
    expect((int) DB::table('invoices')->where('id', $invoiceId)->value('invoice_status'))->toBe(2)
        ->and(fn () => app(CashInvoiceService::class)->confirmCash($invoiceId, makeAdmin()))->toThrow(ValidationException::class)
        ->and(DB::table('payment_transactions')->where('invoice_id', $invoiceId)->count())->toBe(0);
});
