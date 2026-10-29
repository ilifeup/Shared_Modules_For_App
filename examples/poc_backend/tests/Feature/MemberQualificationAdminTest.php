<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\MemberQualificationService;
use App\Support\VerificationPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberQualificationAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_revoke_cannot_be_undone_by_old_contact_verification(): void
    {
        $admin = makeAdmin();
        $member = User::factory()->create([
            'verified_status' => 2,
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
        ]);
        $service = app(MemberQualificationService::class);

        $service->setVerified($member, $admin, false);
        $this->assertSame(0, (int) $member->fresh()->verified_status);
        $this->assertFalse($member->fresh()->isVerifiedMember());
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertNotNull($member->fresh()->phone_verified_at);

        app(VerificationPolicy::class)->grantIfEligible($member->fresh());
        $this->assertSame(0, (int) $member->fresh()->verified_status);

        $service->setVerified($member, $admin, true);
        $this->assertSame(2, (int) $member->fresh()->verified_status);
        $this->assertSame(1, AuditEvent::query()->where('event', 'member.qualification_revoked')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'member.qualification_granted')->count());

        $service->setVerified($member, $admin, true);
        $this->assertSame(1, AuditEvent::query()->where('event', 'member.qualification_granted')->count());
    }

    public function test_non_admin_cannot_change_membership_qualification(): void
    {
        $member = User::factory()->create(['verified_status' => 2]);
        $actor = User::factory()->create(['verified_status' => 2]);

        $this->expectException(AuthorizationException::class);
        app(MemberQualificationService::class)->setVerified($member, $actor, false);
    }
}
