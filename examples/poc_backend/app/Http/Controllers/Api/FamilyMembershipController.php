<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FamilyMembershipController extends Controller
{
    /**
     * Current manager may voluntarily revoke delegation. Historical bookings stay.
     * A member cannot be reassigned by this endpoint; transfer is an admin workflow.
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if ((int) $actor->verified_status !== 2 || ! $actor->is_active) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '家庭代管功能僅供已驗證會員使用。');
        }
        DB::transaction(function () use ($id, $actor): void {
            $membership = DB::table('family_memberships')->where('id', $id)->lockForUpdate()->first();
            if ($membership === null || $membership->status !== 'active'
                || (int) $membership->manager_user_id !== (int) $actor->id) {
                abort(404);
            }

            $now = now();
            DB::table('family_memberships')->where('id', $id)->update([
                'status' => 'revoked',
                'ended_at' => $now,
                'revoked_by' => $actor->id,
                'updated_at' => $now,
            ]);
            DB::table('family_membership_audits')->insert([
                'membership_id' => $id,
                'actor_user_id' => $actor->id,
                'action' => 'revoked',
                'before_data' => json_encode(['status' => 'active'], JSON_THROW_ON_ERROR),
                'after_data' => json_encode(['status' => 'revoked'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return new JsonResponse(['data' => ['id' => $id, 'status' => 'revoked']]);
    }

    public function index(Request $request): JsonResponse
    {
        $manager = $request->user();
        if ((int) $manager->verified_status !== 2 || ! $manager->is_active) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '家庭代管功能僅供已驗證會員使用。');
        }
        $rows = DB::table('family_memberships')
            ->where('manager_user_id', $request->user()->id)
            ->where('status', 'active')
            ->whereNull('ended_at')
            ->join('users as family_member', 'family_member.id', '=', 'family_memberships.member_user_id')
            ->where('family_member.is_active', true)
            ->where('family_member.verified_status', 2)
            ->orderBy('family_memberships.id')
            ->get(['family_memberships.id', 'family_memberships.member_user_id', 'family_memberships.relationship', 'family_memberships.started_at', 'family_member.name']);

        return new JsonResponse(['data' => $rows]);
    }
}
