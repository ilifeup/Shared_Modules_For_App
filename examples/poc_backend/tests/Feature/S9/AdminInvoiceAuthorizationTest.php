<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('rejects verified non-admin invoice operations with HTTP 403 before any writes', function () {
    $member = User::factory()->create(['is_active' => true, 'verified_status' => 2]);

    $endpoints = [
        ['/api/v1/admin/invoice-number-ranges', ['start' => 'AB00000001', 'end' => 'AB00000010', 'period' => '202610']],
        ['/api/v1/admin/invoices/999999/correct-number', ['invoice_no' => 'AB00000001', 'reason' => 'test']],
        ['/api/v1/admin/invoices/999999/allocate-number', []],
        ['/api/v1/admin/invoices/999999/confirm-cash', []],
        ['/api/v1/admin/invoices/999999/record-refund', ['reason' => 'test']],
        ['/api/v1/admin/course-enrollments/999999/confirm-fee', []],
    ];

    foreach ($endpoints as [$url, $payload]) {
        $this->actingAs($member, 'sanctum')->postJson($url, $payload)
            ->assertForbidden()->assertJsonPath('error.code', 'ADMIN_REQUIRED');
    }

    expect(DB::table('invoice_number_ranges')->count())->toBe(0)
        ->and(DB::table('payment_transactions')->count())->toBe(0)
        ->and(DB::table('invoice_refund_audits')->count())->toBe(0);
});
