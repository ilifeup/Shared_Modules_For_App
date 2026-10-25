<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleAccessRule;
use App\Models\User;
use App\Support\VerificationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * S8 M05 read permission, enforced on the server for lists, detail, likes,
 * comments and shared links:
 * - public: anyone
 * - members: logged-in members satisfying the verification policy
 * - restricted: such members whose membership level OR role matches any rule
 */
final class ArticleAccess
{
    public function __construct(
        private readonly VerificationPolicy $policy,
        private readonly MembershipService $memberships,
    ) {}

    /**
     * The member behind an optional Bearer token (null for visitors and disabled accounts).
     */
    public function viewer(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User && $user->is_active ? $user : null;
    }

    /**
     * @return array{levels: list<string>, roles: list<string>}|null null when not a full member
     */
    public function credentials(?User $user): ?array
    {
        if ($user === null || ! $this->policy->isSatisfiedBy($user)) {
            return null;
        }

        return [
            'levels' => [$this->memberships->current($user)['level']->code],
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    /**
     * Why $user may not read $article (null = allowed): login_required |
     * verification_required | forbidden.
     */
    public function denial(Article $article, ?User $user): ?string
    {
        if ($article->visibility === Article::VISIBILITY_PUBLIC) {
            return null;
        }

        if ($user === null) {
            return 'login_required';
        }

        $credentials = $this->credentials($user);
        if ($credentials === null) {
            return 'verification_required';
        }

        if ($article->visibility === Article::VISIBILITY_MEMBERS) {
            return null;
        }

        $matches = $article->accessRules->contains(fn (ArticleAccessRule $rule): bool => ($rule->type === ArticleAccessRule::TYPE_LEVEL && in_array($rule->value, $credentials['levels'], true))
            || ($rule->type === ArticleAccessRule::TYPE_ROLE && in_array($rule->value, $credentials['roles'], true)));

        return $matches ? null : 'forbidden';
    }

    public function canRead(Article $article, ?User $user): bool
    {
        return $this->denial($article, $user) === null;
    }

    /**
     * Restricts a query to articles $user may read.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scope(Builder $query, ?User $user): Builder
    {
        $credentials = $this->credentials($user);

        return $query->where(function (Builder $q) use ($credentials): void {
            $q->where('visibility', Article::VISIBILITY_PUBLIC);

            if ($credentials === null) {
                return;
            }

            $q->orWhere('visibility', Article::VISIBILITY_MEMBERS)
                ->orWhere(fn (Builder $r) => $r->where('visibility', Article::VISIBILITY_RESTRICTED)
                    ->whereHas('accessRules', fn (Builder $rule) => $rule->where(fn (Builder $any) => $any
                        ->where(fn (Builder $w) => $w->where('type', ArticleAccessRule::TYPE_LEVEL)->whereIn('value', $credentials['levels']))
                        ->orWhere(fn (Builder $w) => $w->where('type', ArticleAccessRule::TYPE_ROLE)->whereIn('value', $credentials['roles'] ?: ['__none__'])))));
        });
    }
}
