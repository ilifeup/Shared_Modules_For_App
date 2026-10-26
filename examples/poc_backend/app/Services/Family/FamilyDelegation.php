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
    /**
     * Read-only bulk authorization for a booking group response.
     * Cancellation still rechecks each member under a transaction lock.
     *
     * @param  array<int, int|null>  $memberIds
     */
    public function canManageMemberIds(User $manager, array $memberIds): bool
    {
        if (! $manager->is_active || $memberIds === [] || in_array(null, $memberIds, true)) {
            return false;
        }

        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        if (User::query()->whereIn('id', $ids)->where('is_active', true)->count() !== count($ids)) {
            return false;
        }

        $delegated = array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $manager->id));
        if ($delegated === []) {
            return true;
        }

        return DB::table('family_memberships')
            ->where('manager_user_id', $manager->id)
            ->whereIn('member_user_id', $delegated)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->distinct()
            ->count('member_user_id') === count($delegated);
    }

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
