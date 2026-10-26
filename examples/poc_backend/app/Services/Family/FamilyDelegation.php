<?php

namespace App\Services\Family;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read current delegation at the time of each action; never rely on a cached
 * permission from the moment a booking was created.
 */
final class FamilyDelegation
{
    public function canManage(User $manager, User $member, bool $lock = false): bool
    {
        if (! $manager->is_active || ! $member->is_active) {
            return false;
        }

        if ($manager->id === $member->id) {
            return true;
        }

        $query = DB::table('family_memberships')
            ->where('manager_user_id', $manager->id)
            ->where('member_user_id', $member->id)
            ->where('status', 'active')
            ->whereNull('ended_at');

        // Use inside a transaction for cancellation/transfer race protection.
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() !== null;
    }
}
