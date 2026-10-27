<?php

namespace App\Services\ContentAccess;

use App\Models\User;
use App\Support\VerificationPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shared three-level navigation/content gate.
 * An explicit deny overrides any matching allow. Every ancestor must allow.
 * This is independent of booking and media-playback entitlements.
 */
final class ContentAccessPolicy
{
    public function __construct(private readonly VerificationPolicy $verification) {}

    public function allows(?User $user, string $page, ?string $list = null, ?string $topic = null): bool
    {
        if ($topic !== null && $list === null) {
            throw new InvalidArgumentException('Topic requires a parent list.');
        }

        if (! $this->allowsResource($user, 'page', $page)) {
            return false;
        }

        if ($list !== null && ! $this->allowsResource($user, 'list', $page.'/'.$list)) {
            return false;
        }

        return $topic === null || $this->allowsResource($user, 'topic', $page.'/'.$list.'/'.$topic);
    }

    public function allowsResource(?User $user, string $scope, string $code): bool
    {
        if (! in_array($scope, ['page', 'list', 'topic'], true)) {
            throw new InvalidArgumentException('Unknown access scope.');
        }

        if ($user !== null && ! $user->is_active) {
            return false;
        }

        $audiences = $user === null ? ['guest', 'all'] : ['member', 'all', 'user:'.$user->id];
        if ($user !== null && $this->verification->isSatisfiedBy($user)) {
            $audiences[] = 'verified';
        }

        // Active membership levels are entitlements, never administrator roles.
        if ($user !== null) {
            $levelIds = DB::table('member_level_assignments')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('starts_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->pluck('membership_level_id');

            foreach ($levelIds as $levelId) {
                $audiences[] = 'level:'.$levelId;
            }
        }

        // A public rule never overrides an explicit guest/member denial.
        $rules = DB::table('app_content_access_rules')
            ->where('scope', $scope)
            ->where('resource_code', $code)
            ->whereIn('audience', $audiences)
            ->pluck('allowed');

        if ($rules->contains(fn ($allowed): bool => ! (bool) $allowed)) {
            return false;
        }

        return $rules->contains(fn ($allowed): bool => (bool) $allowed);
    }
}
