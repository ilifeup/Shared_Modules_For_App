<?php

namespace Tests\Feature\S9;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_non_admin_cannot_mutate_invoice_or_course_payment(): void
    {
        $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);

        $requests = [
            ['/api/v1/admin/invoice-number-ranges', ['start' => 'AB00000001', 'end' => 'AB00000002', 'period' => now()->format('Ym')]],
            ['/api/v1/admin/invoices/999999/correct-number', ['invoice_no' => 'AB00000001', 'reason' => 'test']],
            ['/api/v1/admin/invoices/999999/allocate-number', []],
            ['/api/v1/admin/invoices/999999/record-refund', ['reason' => 'test']],
            ['/api/v1/admin/invoices/999999/confirm-cash', []],
            ['/api/v1/admin/course-enrollments/999999/confirm-fee', []],
        ];

        foreach ($requests as [$url, $payload]) {
            $this->actingAs($member, 'sanctum')->postJson($url, $payload)
                ->assertForbidden()->assertJsonPath('error.code', 'ADMIN_REQUIRED');
        }

        $this->assertSame(0, DB::table('invoice_number_ranges')->count());
        $this->assertSame(0, DB::table('payment_transactions')->count());
        $this->assertSame(0, DB::table('invoice_refund_audits')->count());
    }

    public function test_inactive_admin_cannot_mutate_invoice_or_course_payment(): void
    {
        $admin = User::factory()->create(['verified_status' => 2, 'is_active' => false]);
        $admin->assignRole(User::ADMIN_ROLE);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/invoice-number-ranges', [
                'start' => 'AB00000001', 'end' => 'AB00000002', 'period' => now()->format('Ym'),
            ])->assertForbidden();
        $this->assertSame(0, DB::table('invoice_number_ranges')->count());
    }
}
