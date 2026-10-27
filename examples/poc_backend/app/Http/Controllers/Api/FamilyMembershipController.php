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

    /**
     * Edit profile contact details of an existing, verified delegated family member.
     * This never changes users credentials or verified_status.
     */
    public function saveProfile(Request $request, int $memberId): JsonResponse
    {
        $manager = $request->user();
        if ((int) $manager->verified_status !== 2 || ! $manager->is_active) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '家庭代管功能僅供已驗證會員使用。');
        }

        $data = $request->validate([
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        $profile = DB::transaction(function () use ($manager, $memberId, $data): object {
            $delegation = DB::table('family_memberships')
                ->where('manager_user_id', $manager->id)
                ->where('member_user_id', $memberId)
                ->where('status', 'active')
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();
            $eligible = DB::table('users')->where('id', $memberId)
                ->where('is_active', true)->where('verified_status', 2)->exists();
            if ($delegation === null || ! $eligible) {
                throw new ApiException(403, 'FAMILY_MEMBER_NOT_AUTHORIZED', '無有效代管授權或家庭成員尚未驗證。');
            }

            DB::table('family_member_profiles')->updateOrInsert(
                ['member_user_id' => $memberId],
                [
                    'birth_date' => $data['birth_date'],
                    'address' => trim($data['address']),
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'contact_email' => $data['contact_email'] ?? null,
                    'updated_at' => now(),
                ],
            );

            return DB::table('family_member_profiles')->where('member_user_id', $memberId)->first();
        });

        return new JsonResponse(['data' => $profile]);
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
