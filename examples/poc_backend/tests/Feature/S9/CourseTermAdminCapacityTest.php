<?php

namespace Tests\Feature\S9;

use App\Filament\Resources\CourseTerms\Pages\EditCourseTerm;
use App\Models\CourseTerm;
use App\Models\User;
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
}
