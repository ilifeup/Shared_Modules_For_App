<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Admin qualification changes. Only users.verified_status determines membership.
 * Status 0 is an admin-revoked, unverified account; 1 is pending; 2 is verified.
 * Both 0 and 1 are the same external "registered, not verified" membership type.
 */
final class MemberQualificationService
{
    public function setVerified(User $member, User $actor, bool $verified): void
    {
        if (! $actor->is_active || ! $actor->isAdmin() || ! $actor->can('members.manage')) {
            throw new AuthorizationException('Only the administrator may change membership qualification.');
        }

        DB::transaction(function () use ($member, $actor, $verified): void {
            $locked = User::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAdmin()) {
                throw new AuthorizationException('Administrator qualification cannot be changed here.');
            }

            // Revoked status 0 cannot be silently re-granted by an old verified
            // email/phone timestamp: VerificationPolicy only auto-grants status 1.
            $next = $verified ? 2 : 0;
            $previous = (int) $locked->verified_status;
            if ($previous === $next) {
                return;
            }

            $locked->forceFill(['verified_status' => $next])->save();
            AuditEvent::record(
                $verified ? 'member.qualification_granted' : 'member.qualification_revoked',
                $actor->id,
                $locked,
                ['previous_status' => $previous, 'new_status' => $next],
            );
        });
    }
}
