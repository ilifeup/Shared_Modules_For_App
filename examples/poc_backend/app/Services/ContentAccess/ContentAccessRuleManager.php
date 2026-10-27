<?php

namespace App\Services\ContentAccess;

use App\Models\AuditEvent;
use App\Models\User;
use Filament\Facades\Filament;
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

        DB::transaction(function () use ($actor, $scope, $code, $audience, $allowed): void {
            $key = ['scope' => $scope, 'resource_code' => $code, 'audience' => $audience];
            $previous = DB::table('app_content_access_rules')->where($key)->first(['allowed']);

            if ($previous !== null && (bool) $previous->allowed === $allowed) {
                return;
            }

            if ($previous === null) {
                DB::table('app_content_access_rules')->insert($key + [
                    'allowed' => $allowed, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('app_content_access_rules')->where($key)->update([
                    'allowed' => $allowed, 'updated_at' => now(),
                ]);
            }

            AuditEvent::record('content.access_rule_saved', $actor->id, null, [
                'scope' => $scope, 'resource' => $code, 'audience' => $audience,
                'old' => $previous === null ? null : (bool) $previous->allowed,
                'new' => $allowed,
            ]);
        });
    }

    public function remove(User $actor, string $scope, string $code, string $audience): void
    {
        $this->authorize($actor);
        $this->validateRule($scope, $code, $audience);

        DB::transaction(function () use ($actor, $scope, $code, $audience): void {
            $key = ['scope' => $scope, 'resource_code' => $code, 'audience' => $audience];
            $previous = DB::table('app_content_access_rules')->where($key)->first(['allowed']);

            if ($previous === null) {
                return;
            }

            DB::table('app_content_access_rules')->where($key)->delete();
            AuditEvent::record('content.access_rule_removed', $actor->id, null, [
                'scope' => $scope, 'resource' => $code, 'audience' => $audience,
                'old' => (bool) $previous->allowed,
            ]);
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->canAccessPanel(Filament::getPanel('admin')) && $actor->can('settings.manage'), 403);
    }

    private function validateRule(string $scope, string $code, string $audience): void
    {
        if (! in_array($scope, ['page', 'list', 'topic'], true)
            || strlen($code) > 120
            || ! preg_match('/^[a-z0-9_-]+(?:\/[a-z0-9_-]+){0,2}$/', $code)
            || substr_count($code, '/') !== ['page' => 0, 'list' => 1, 'topic' => 2][$scope]
            || ! (in_array($audience, ['guest', 'register_member', 'verified_member'], true) || preg_match('/^(user|level|badge):[1-9][0-9]*$/', $audience))
        ) {
            throw new InvalidArgumentException('Invalid content access rule.');
        }
    }
}
