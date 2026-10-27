<?php

namespace App\Services\ContentAccess;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Backend mutation boundary for administrator-managed App/list/topic rules.
 * Call from an admin-only UI; never expose this manager on a public endpoint.
 */
final class ContentAccessRuleManager
{
    public function save(User $actor, string $scope, string $code, string $audience, bool $allowed): void
    {
        $this->authorize($actor);
        $this->validateRule($scope, $code, $audience);

        DB::table('app_content_access_rules')->updateOrInsert(
            ['scope' => $scope, 'resource_code' => $code, 'audience' => $audience],
            ['allowed' => $allowed, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function remove(User $actor, string $scope, string $code, string $audience): void
    {
        $this->authorize($actor);
        $this->validateRule($scope, $code, $audience);

        DB::table('app_content_access_rules')
            ->where('scope', $scope)
            ->where('resource_code', $code)
            ->where('audience', $audience)
            ->delete();
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && $actor->hasRole(User::ADMIN_ROLE), 403);
    }

    private function validateRule(string $scope, string $code, string $audience): void
    {
        if (! in_array($scope, ['page', 'list', 'topic'], true)
            || strlen($code) > 120
            || ! preg_match('/^[a-z0-9_-]+(?:\/[a-z0-9_-]+){0,2}$/', $code)
            || substr_count($code, '/') !== ['page' => 0, 'list' => 1, 'topic' => 2][$scope]
            || ! (in_array($audience, ['all', 'guest', 'member', 'verified'], true) || preg_match('/^user:[1-9][0-9]*$/', $audience))
        ) {
            throw new InvalidArgumentException('Invalid content access rule.');
        }
    }
}
