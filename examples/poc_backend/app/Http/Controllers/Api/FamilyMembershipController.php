<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FamilyMembershipController extends Controller
{
    /**
     * Create a new, verified, non-login family member with an independent users.id.
     * Contact information belongs to the family profile, not login credentials.
     */
    public function store(Request $request): JsonResponse
    {
        $manager = $request->user();
        if ((int) $manager->verified_status !== 2 || ! $manager->is_active || $manager->FamilyUser) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '家庭代管功能僅供已驗證的一般會員使用。');
        }

        // Family creation is suspended until an administrator-approved
        // relationship-evidence application can be verified server-side.
        // Do not treat contact verification or a client flag as approval.
        throw new ApiException(403, 'FAMILY_APPROVAL_REQUIRED', '須先提交家庭關係證明並經管理員核准，才能新增家庭會員。');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ]);

        $result = DB::transaction(function () use ($manager, $data): array {
            // Serialize creation per manager; counting without this row lock allows
            // concurrent requests to bypass the one-member free allowance.
            User::query()->whereKey($manager->id)->lockForUpdate()->firstOrFail();
            $existingCount = DB::table('family_memberships')
                ->where('manager_user_id', $manager->id)->count();
            // External Services paid expansion is not implemented/authorized yet.
            // Never assume payment from a client-supplied flag or stale UI.
            if ($existingCount >= 1) {
                throw new ApiException(403, 'FAMILY_MEMBER_LIMIT_REACHED', '免費家庭會員上限為 1 位；增加名額需先啟用 External Services 付費擴充。');
            }

            $now = now();
            // Trusted family creation sets protected membership/login flags atomically.
            $member = new User;
            $member->forceFill([
                'name' => trim($data['name']),
                'display_name' => mb_substr(trim($data['name']), 0, 80),
                'email' => null,
                'phone_e164' => null,
                'password' => Str::random(64),
                'is_active' => true,
                'verified_status' => 2,
                'FamilyUser' => true,
            ])->save();
            $membershipId = DB::table('family_memberships')->insertGetId([
                'member_user_id' => $member->id,
                'manager_user_id' => $manager->id,
                'relationship' => $data['relationship'] ?? null,
                'status' => 'active',
                'started_at' => $now,
                'created_by' => $manager->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('family_member_profiles')->insert([
                'member_user_id' => $member->id,
                'birth_date' => $data['birth_date'],
                'address' => trim($data['address']),
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('family_membership_audits')->insert([
                'membership_id' => $membershipId,
                'actor_user_id' => $manager->id,
                'action' => 'created',
                'before_data' => null,
                'after_data' => json_encode(['member_user_id' => $member->id, 'verified_status' => 2, 'FamilyUser' => true], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $membershipId, 'member_user_id' => $member->id, 'verified_status' => 2, 'FamilyUser' => true];
        });

        return new JsonResponse(['data' => $result], 201);
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
