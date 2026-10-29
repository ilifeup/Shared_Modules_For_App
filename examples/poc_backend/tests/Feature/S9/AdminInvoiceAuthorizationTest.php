<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('returns 403 rather than validation 422 for non-admin invoice operations', function () {
    $member = User::factory()->create(['verified_status' => 2, 'is_active' => true]);
    $this->actingAs($member, 'sanctum');

    $cases = [
        ['/api/v1/admin/invoice-number-ranges', ['start' => 'AA00000001', 'end' => 'AA00000002', 'period' => '202610']],
        ['/api/v1/admin/invoices/999999/correct-number', ['invoice_no' => 'AA00000001', 'reason' => 'test']],
        ['/api/v1/admin/invoices/999999/allocate-number', []],
        ['/api/v1/admin/invoices/999999/confirm-cash', []],
        ['/api/v1/admin/invoices/999999/record-refund', ['reason' => 'test']],
        ['/api/v1/admin/course-enrollments/999999/confirm-fee', []],
    ];

    foreach ($cases as [$url, $payload]) {
        $this->postJson($url, $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ADMIN_REQUIRED');
    }

    expect(DB::table('payment_transactions')->count())->toBe(0);
});
