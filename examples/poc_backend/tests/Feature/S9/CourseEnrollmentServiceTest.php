<?php

namespace Tests\Feature\S9;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CourseEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function term(int $capacity = 1, int $waitlist = 5): int
    {
        $termId = DB::table('course_terms')->insertGetId([
            'title' => 'MVP test term',
            'price_amount' => 1,
            'enrollment_capacity' => $capacity,
            'waitlist_capacity' => $waitlist,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = s9Event(['booking_capacity' => max(2, $capacity + $waitlist + 1)]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $termId,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $termId;
    }

    private function verified(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['verified_status' => 2, 'is_active' => true, 'FamilyUser' => false])->save();

        return $user;
    }

    public function test_paid_check_rejects_missing_invoice_without_recording_payment(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'course_term_id' => $term,
            'user_id' => $member->id,
            'booker_user_id' => $member->id,
            'status' => 'pending_payment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(CourseEnrollmentService::class)->paidCheck($enrollmentId, $admin);
            $this->fail('A course cannot be marked paid without an invoice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollmentId)->status);
        $this->assertSame(0, DB::table('payment_transactions')->count());
    }

    public function test_term_enrollment_books_all_sessions_with_one_invoice_and_cash_confirmation(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        $events = [s9Event(['booking_capacity' => 2]), s9Event(['booking_capacity' => 2])];
        foreach ($events as $event) {
            DB::table('course_sessions')->insert([
                'course_term_id' => $term,
                'calendar_event_id' => $event->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        $this->assertSame('pending_payment', $enrollment['status']);
        $this->assertSame(3, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->count());
        $this->assertSame(0, DB::table('invoices')->where('booking_type', '01')->count());

        $invoiceId = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->value('id');
        $paid = app(\App\Services\Payment\CashInvoiceService::class)->confirmCash((int) $invoiceId, $admin);
        $this->assertSame(1, $paid['status']);
        $this->assertSame('confirmed', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertSame(1, DB::table('payment_transactions')->where('invoice_id', $invoiceId)->count());
    }

    public function test_paid_course_creates_exactly_one_term_invoice_without_session_invoices(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term, 'calendar_event_id' => $event->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $invoices = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $result['id'])->get();
        $this->assertCount(1, $invoices);
        $this->assertSame('1.00', number_format((float) $invoices[0]->invoice_amount, 2, '.', ''));
        $this->assertSame(0, DB::table('invoices')->where('booking_type', '01')->count());
        $this->assertSame('pending_payment', $result['status']);
    }

    public function test_cash_confirmation_rejects_course_without_session_bookings(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        DB::table('event_booking_groups')
            ->where('client_request_id', (string) \Ramsey\Uuid\Uuid::uuid5(
                \Ramsey\Uuid\Uuid::NAMESPACE_URL,
                'course-enrollment:'.$enrollment['id'].':event:'.$event->id,
            ))->update(['status' => 'cancelled']);

        $invoice = DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->first();
        $this->assertNotNull($invoice);
        $this->assertSame('1.00', number_format((float) $invoice->invoice_amount, 2, '.', ''));

        try {
            app(\App\Services\Payment\CashInvoiceService::class)->confirmCash((int) $invoice->id, $admin);
            $this->fail('Course payment cannot be confirmed without all session bookings.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertSame(0, (int) DB::table('invoices')->find($invoice->id)->invoice_status);
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertSame(0, DB::table('payment_transactions')->where('invoice_id', $invoice->id)->count());
    }

    public function test_term_capacity_and_fifo_waitlist_are_independent(): void
    {
        $term = $this->term();
        $service = app(CourseEnrollmentService::class);
        $first = $this->verified();
        $this->assertSame('pending_payment', $service->enroll($term, $first, $first)['status']);

        for ($i = 1; $i <= 5; $i++) {
            $member = $this->verified();
            $result = $service->enroll($term, $member, $member);
            $this->assertSame('waitlisted', $result['status']);
            $this->assertSame($i, $result['waitlist_sequence']);
        }

        $extra = $this->verified();
        $this->expectException(ValidationException::class);
        $service->enroll($term, $extra, $extra);
    }

    public function test_zero_waitlist_capacity_blocks_additional_enrollment(): void
    {
        $term = $this->term(1, 0);
        $service = app(CourseEnrollmentService::class);
        $member = $this->verified();
        $service->enroll($term, $member, $member);
        $second = $this->verified();
        $this->expectException(ValidationException::class);
        $service->enroll($term, $second, $second);
    }

    public function test_unverified_member_cannot_enroll(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $member->forceFill(['verified_status' => 1])->save();
        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->enroll($term, $member, $member);
    }

    public function test_legacy_confirm_fee_is_disabled_even_when_session_bookings_exist(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        $this->assertSame(1, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
        try {
            app(CourseEnrollmentService::class)->confirmFee($enrollment['id'], $admin);
            $this->fail('Legacy confirmation must remain disabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
    }


    public function test_paid_check_confirms_existing_session_bookings_without_duplicates(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $before = DB::table('event_booking_attendees')->where('member_user_id', $member->id)
            ->where('status', 'confirmed')->count();
        $this->assertSame(1, $before);
        $this->assertSame('confirmed', $service->paidCheck($enrollment['id'], $admin)['status']);
        $this->assertTrue($service->paidCheck($enrollment['id'], $admin)['replayed']);
        $this->assertSame($before, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
        $this->assertNotNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }


    public function test_readiness_reports_existing_session_bookings_without_mutating_payment(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $before = DB::table('event_booking_attendees')->where('member_user_id', $member->id)->count();
        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertTrue($result['ready']);
        $this->assertSame(1, $result['session_count']);
        $this->assertSame([], $result['invalid_session_event_ids']);
        $this->assertSame($before, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->count());
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }


    public function test_readiness_rechecks_current_database_member_qualification(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);

        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($service->confirmationReadiness($enrollment['id'], $admin)['ready']);

        $member->forceFill(['verified_status' => 1])->save();
        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertFalse($result['ready']);
        $this->assertSame('會員資格或代管權限不符。', $result['reason']);
        $this->assertSame([], $result['invalid_session_event_ids']);
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->count());
    }

    public function test_readiness_rechecks_existing_family_manager_qualification(): void
    {
        $term = $this->term();
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $manager, $family);
        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $this->assertTrue($service->confirmationReadiness($enrollment['id'], $admin)['ready']);

        $manager->forceFill(['is_active' => false])->save();
        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertFalse($result['ready']);
        $this->assertSame('會員資格或代管權限不符。', $result['reason']);
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $family->id)->count());
    }

    public function test_enrollment_rolls_back_all_sessions_if_one_is_unavailable(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $event = s9Event(['booking_capacity' => 2, 'booking_enabled' => false]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term, 'calendar_event_id' => $event->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(CourseEnrollmentService::class)->enroll($term, $member, $member);
            $this->fail('Enrollment must roll back when any session cannot be booked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }
        $this->assertSame(0, DB::table('course_enrollments')->where('course_term_id', $term)->count());
        $this->assertSame(0, DB::table('invoices')->where('booking_type', '02')->count());
        $this->assertSame(0, DB::table('event_booking_attendees')->where('member_user_id', $member->id)->count());
    }


    public function test_active_family_manager_books_twelve_sessions_before_cash_confirmation(): void
    {
        $term = $this->term();
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id, 'member_user_id' => $family->id,
            'status' => 'active', 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 1; $i < 12; $i++) {
            $event = s9Event(['booking_capacity' => 2]);
            DB::table('course_sessions')->insert([
                'course_term_id' => $term, 'calendar_event_id' => $event->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $manager, $family);
        $this->assertSame('pending_payment', $enrollment['status']);
        $this->assertSame(12, DB::table('event_booking_attendees')
            ->where('member_user_id', $family->id)->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('invoices')->where('booking_type', '02')
            ->where('booking_id', $enrollment['id'])->count());
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $this->assertSame('confirmed', $service->paidCheck($enrollment['id'], $admin)['status']);
        $this->assertSame(12, DB::table('event_booking_attendees')
            ->where('member_user_id', $family->id)->where('status', 'confirmed')->count());
    }


    public function test_revoked_family_delegation_blocks_fee_confirmation_and_all_session_bookings(): void
    {
        $term = $this->term();
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $manager, $family);
        $event = s9Event(['booking_capacity' => 2]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('family_memberships')->where('manager_user_id', $manager->id)
            ->where('member_user_id', $family->id)
            ->update(['status' => 'ended', 'ended_at' => now()]);

        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        try {
            $service->confirmFee($enrollment['id'], $admin);
            $this->fail('Revoked delegation must block fee confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('member', $exception->errors());
        }
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertSame(0, DB::table('event_booking_attendees')
            ->where('member_user_id', $family->id)->count());
    }

    public function test_confirm_fee_api_rejects_unauthenticated_and_non_admin_requests(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        $url = '/api/v1/admin/course-enrollments/'.$enrollment['id'].'/confirm-fee';

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($member, 'sanctum')->postJson($url)->assertUnprocessable();
        $this->assertSame('pending_payment', DB::table('course_enrollments')->find($enrollment['id'])->status);
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_confirm_fee_api_checks_cash_without_rebooking_sessions(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);
        $url = '/api/v1/admin/course-enrollments/'.$enrollment['id'].'/confirm-fee';
        $before = DB::table('event_booking_attendees')->where('member_user_id', $member->id)
            ->where('status', 'confirmed')->count();
        $this->assertSame(1, $before);
        $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk()
            ->assertJsonPath('data.replayed', true);
        $this->assertSame($before, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
    }


    public function test_non_admin_cannot_confirm_fee(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->confirmFee($enrollment['id'], $member);
    }

    public function test_admin_readiness_preserves_pending_payment_and_existing_bookings(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertTrue($result['ready']);
        $this->assertSame(1, $result['session_count']);
        $this->assertSame('pending_payment', $result['status']);
        $this->assertSame(1, DB::table('event_booking_attendees')
            ->where('member_user_id', $member->id)->where('status', 'confirmed')->count());
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }


    public function test_readiness_reports_invalid_session_events_without_confirming_fee(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $service = app(CourseEnrollmentService::class);
        $enrollment = $service->enroll($term, $member, $member);
        $admin = $this->verified();
        $admin->assignRole(User::ADMIN_ROLE);

        $event = CalendarEvent::query()->create([
            'title' => 'Unpublished course session',
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(10)->addHours(2),
            'status' => 'draft',
            'audience' => 'members',
            'booking_enabled' => false,
        ]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $service->confirmationReadiness($enrollment['id'], $admin);
        $this->assertFalse($result['ready']);
        $this->assertSame(1, $result['session_count']);
        $this->assertSame([$event->id], $result['invalid_session_event_ids']);
        $this->assertNull(DB::table('course_enrollments')->find($enrollment['id'])->fee_confirmed_at);
    }

    public function test_my_course_enrollments_hide_revoked_and_unverified_family_members(): void
    {
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        $other = $this->verified();
        $term = $this->term(3);
        $now = now();

        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = app(CourseEnrollmentService::class);
        $own = $service->enroll($term, $manager, $manager);
        $delegated = $service->enroll($term, $manager, $family);
        $service->enroll($term, $other, $other);

        $url = '/api/v1/me/course-enrollments';
        $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(2, 'data');

        $family->forceFill(['verified_status' => 0])->save();
        $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own['id']);

        $family->forceFill(['verified_status' => 2])->save();
        DB::table('family_memberships')
            ->where('manager_user_id', $manager->id)
            ->where('member_user_id', $family->id)
            ->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($manager, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotEquals($delegated['id'], $response->json('data.0.id'));
    }

    public function test_delegated_family_history_is_limited_to_two_months_but_own_history_is_not(): void
    {
        $manager = $this->verified();
        $family = $this->verified();
        $family->forceFill(['FamilyUser' => true])->save();
        $term = $this->term(3);
        $now = now();

        DB::table('family_memberships')->insert([
            'manager_user_id' => $manager->id,
            'member_user_id' => $family->id,
            'status' => 'active',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = app(CourseEnrollmentService::class);
        $own = $service->enroll($term, $manager, $manager);
        $familyEnrollment = $service->enroll($term, $manager, $family);

        DB::table('course_enrollments')->whereIn('id', [$own['id'], $familyEnrollment['id']])
            ->update(['created_at' => now()->subMonths(3)]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/me/course-enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own['id']);
    }

    public function test_non_admin_cannot_read_fee_confirmation_readiness(): void
    {
        $term = $this->term();
        $member = $this->verified();
        $enrollment = app(CourseEnrollmentService::class)->enroll($term, $member, $member);

        $this->expectException(ValidationException::class);
        app(CourseEnrollmentService::class)->confirmationReadiness($enrollment['id'], $member);
    }

    public function test_course_catalog_excludes_terms_with_incomplete_sessions(): void
    {
        $visibleTerm = $this->term();
        $hiddenTerm = $this->term();
        $future = now()->addDays(10);
        $ready = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $unavailable = s9Event(['booking_enabled' => false, 'booking_capacity' => 2]);
        $ready->forceFill(['starts_at' => $future, 'ends_at' => $future->copy()->addHour(), 'status' => 'published'])->save();
        $unavailable->forceFill(['starts_at' => $future, 'ends_at' => $future->copy()->addHour(), 'status' => 'published'])->save();

        $otherReady = s9Event(['booking_enabled' => true, 'booking_capacity' => 2]);
        $otherReady->forceFill(['starts_at' => $future, 'ends_at' => $future->copy()->addHour(), 'status' => 'published'])->save();
        foreach ([[$visibleTerm, $ready->id], [$hiddenTerm, $otherReady->id]] as $item) {
            DB::table('course_sessions')->insert(['course_term_id' => $item[0], 'calendar_event_id' => $item[1], 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('course_sessions')->insert(['course_term_id' => $hiddenTerm, 'calendar_event_id' => $unavailable->id, 'created_at' => now(), 'updated_at' => now()]);

        $member = $this->verified();
        $response = $this->actingAs($member, 'sanctum')->getJson('/api/v1/course-terms')->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($visibleTerm, $ids);
        $this->assertNotContains($hiddenTerm, $ids);
    }
}
