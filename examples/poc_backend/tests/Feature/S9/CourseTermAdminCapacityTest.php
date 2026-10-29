<?php

namespace Tests\Feature\S9;

use App\Filament\Resources\CourseTerms\Pages\EditCourseTerm;
use App\Models\CourseTerm;
use App\Models\User;
use App\Services\Course\CourseEnrollmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CourseTermAdminCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function term(): CourseTerm
    {
        return CourseTerm::query()->create([
            'title' => 'Capacity guarded term',
            'price_amount' => 1,
            'enrollment_capacity' => 2,
            'waitlist_capacity' => 2,
        ]);
    }

    private function enrollment(CourseTerm $term, string $status): void
    {
        $member = User::factory()->create();
        DB::table('course_enrollments')->insert([
            'course_term_id' => $term->id,
            'user_id' => $member->id,
            'booker_user_id' => $member->id,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function loginAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(makeAdmin());
    }

    public function test_admin_cannot_shrink_term_capacity_below_reserved_seats(): void
    {
        $this->loginAdmin();
        $term = $this->term();
        $this->enrollment($term, 'pending_payment');
        $this->enrollment($term, 'confirmed');

        Livewire::test(EditCourseTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['enrollment_capacity' => 1])
            ->call('save')
            ->assertHasFormErrors(['enrollment_capacity']);

        $this->assertSame(2, (int) $term->fresh()->enrollment_capacity);
        $this->assertSame(0, DB::table('audit_events')->where('event', 'course.capacity_changed')->count());
    }

    public function test_admin_cannot_shrink_waitlist_below_existing_fifo_entries(): void
    {
        $this->loginAdmin();
        $term = $this->term();
        $this->enrollment($term, 'waitlisted');
        $this->enrollment($term, 'waitlisted');

        Livewire::test(EditCourseTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['waitlist_capacity' => 1])
            ->call('save')
            ->assertHasFormErrors(['waitlist_capacity']);

        $this->assertSame(2, (int) $term->fresh()->waitlist_capacity);
    }

    public function test_admin_can_increase_capacity_with_audited_change(): void
    {
        $this->loginAdmin();
        $term = $this->term();
        $this->enrollment($term, 'confirmed');

        Livewire::test(EditCourseTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['enrollment_capacity' => 3, 'waitlist_capacity' => 4])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, (int) $term->fresh()->enrollment_capacity);
        $this->assertSame(4, (int) $term->fresh()->waitlist_capacity);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'course.capacity_changed')->count());
    }

    private function verifiedMember(): User
    {
        return User::factory()->create(['is_active' => true, 'verified_status' => 2]);
    }

    private function addTermSession(CourseTerm $term): void
    {
        $event = s9Event(['booking_capacity' => 5, 'starts_at' => now()->addDays(7)]);
        DB::table('course_sessions')->insert([
            'course_term_id' => $term->id,
            'calendar_event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_expansion_promotes_existing_term_waitlist_in_fifo_order(): void
    {
        $this->loginAdmin();
        $term = $this->term();
        $term->update(['enrollment_capacity' => 1]);
        $this->addTermSession($term);
        $service = app(CourseEnrollmentService::class);
        $first = $this->verifiedMember();
        $second = $this->verifiedMember();
        $third = $this->verifiedMember();

        $one = $service->enroll($term->id, $first, $first);
        $two = $service->enroll($term->id, $second, $second);
        $three = $service->enroll($term->id, $third, $third);
        $this->assertSame('pending_payment', $one['status']);
        $this->assertSame('waitlisted', $two['status']);
        $this->assertSame('waitlisted', $three['status']);

        Livewire::test(EditCourseTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['enrollment_capacity' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach ([$two['id'], $three['id']] as $id) {
            $this->assertSame('pending_payment', DB::table('course_enrollments')->find($id)->status);
            $this->assertSame(1, DB::table('invoices')->where('booking_type', '02')
                ->where('booking_id', $id)->where('invoice_status', 0)->count());
        }
        $this->assertSame(3, DB::table('event_booking_attendees')
            ->where('status', 'confirmed')->where('active', true)->count());
        $this->assertSame(0, DB::table('course_enrollments')
            ->where('course_term_id', $term->id)->where('status', 'waitlisted')->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('event', 'course.capacity_changed')->count());
    }

    public function test_admin_expansion_never_skips_ineligible_fifo_head(): void
    {
        $this->loginAdmin();
        $term = $this->term();
        $term->update(['enrollment_capacity' => 1]);
        $this->addTermSession($term);
        $service = app(CourseEnrollmentService::class);
        $first = $this->verifiedMember();
        $second = $this->verifiedMember();
        $third = $this->verifiedMember();

        $service->enroll($term->id, $first, $first);
        $two = $service->enroll($term->id, $second, $second);
        $three = $service->enroll($term->id, $third, $third);
        $second->forceFill(['verified_status' => 0])->save();

        Livewire::test(EditCourseTerm::class, ['record' => $term->getRouteKey()])
            ->fillForm(['enrollment_capacity' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('waitlisted', DB::table('course_enrollments')->find($two['id'])->status);
        $this->assertSame('waitlisted', DB::table('course_enrollments')->find($three['id'])->status);
        $this->assertSame(1, DB::table('invoices')->where('booking_type', '02')->count());
        $this->assertSame(1, DB::table('event_booking_attendees')
            ->where('status', 'confirmed')->where('active', true)->count());
    }
}
