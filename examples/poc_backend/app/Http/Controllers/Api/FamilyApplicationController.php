<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class FamilyApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if ((int) $actor->verified_status !== 2 || ! $actor->is_active || $actor->FamilyUser) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', 'Only verified members may apply.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', 'string', 'max:40'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'address' => ['required', 'string', 'max:500'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'relationship_evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        // A second or later request requires a verified payment entitlement.
        // Until payment integration is available, do not accept a client-side paid flag.
        $alreadyCreated = DB::table('family_memberships')->where('manager_user_id', $actor->id)->count();
        $pendingOrApproved = DB::table('family_member_applications')->where('manager_user_id', $actor->id)
            ->whereIn('status', ['pending', 'approved'])->whereNull('created_member_user_id')->exists();
        if ($pendingOrApproved) {
            throw new ApiException(409, 'FAMILY_APPLICATION_PENDING', '請先完成目前申請的審核或建立。');
        }
        $max = (int) DB::table('family_member_settings')->where('id', 1)->value('max_members');
        if ($alreadyCreated >= $max) {
            throw new ApiException(403, 'FAMILY_MEMBER_LIMIT_REACHED', '已達管理員設定的家庭會員上限。');
        }
        if ($alreadyCreated >= 1 && ! DB::table('family_member_paid_credits')
            ->where('manager_user_id', $actor->id)->whereNull('used_application_id')->exists()) {
            throw new ApiException(403, 'FAMILY_PAYMENT_REQUIRED', '第二位起須先完成付費，才能提出申請。');
        }

        $path = $request->file('relationship_evidence')->store('family-evidence', 'local');
        try {
            $id = DB::transaction(function () use ($actor, $data, $path) {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                $count = DB::table('family_memberships')->where('manager_user_id', $actor->id)->count();
                $maxNow = (int) DB::table('family_member_settings')->where('id', 1)->value('max_members');
                if ($count >= $maxNow) {
                    throw new ApiException(403, 'FAMILY_MEMBER_LIMIT_REACHED', '已達管理員設定的上限。');
                }
                if (DB::table('family_member_applications')->where('manager_user_id', $actor->id)
                    ->whereIn('status', ['pending', 'approved'])->whereNull('created_member_user_id')->exists()) {
                    throw new ApiException(409, 'FAMILY_APPLICATION_PENDING', '請先完成現有申請。');
                }
                $credit = $count > 0 ? DB::table('family_member_paid_credits')
                    ->where('manager_user_id', $actor->id)->whereNull('used_application_id')
                    ->orderBy('id')->lockForUpdate()->first() : null;
                if ($count > 0 && $credit === null) {
                    throw new ApiException(403, 'FAMILY_PAYMENT_REQUIRED', '請先付費。');
                }
                $id = DB::table('family_member_applications')->insertGetId([
                'manager_user_id' => $actor->id,
                'member_name' => trim($data['name']),
                'relationship' => $data['relationship'],
                'birth_date' => $data['birth_date'],
                'address' => trim($data['address']),
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'evidence_path' => $path,
                'status' => 'pending',
                'payment_verified' => $credit !== null,
                'payment_verified_by' => $credit?->verified_by,
                'payment_verified_at' => $credit?->created_at,
                'created_at' => now(),
                'updated_at' => now(),
                ]);
                if ($credit !== null) {
                    DB::table('family_member_paid_credits')->where('id', $credit->id)
                        ->update(['used_application_id' => $id, 'updated_at' => now()]);
                }
                return $id;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return new JsonResponse(['data' => ['id' => $id, 'status' => 'pending']], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if ((int) $actor->verified_status !== 2 || ! $actor->is_active || $actor->FamilyUser) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', 'Only verified members may view applications.');
        }

        $rows = DB::table('family_member_applications')
            ->where('manager_user_id', $actor->id)
            ->orderByDesc('id')
            ->get(['id', 'member_name', 'relationship', 'status', 'reviewed_at', 'created_at']);

        return new JsonResponse(['data' => $rows]);
    }
    public function review(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->is_active || ! $request->user()->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', 'Administrator approval required.');
        }
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = DB::transaction(function () use ($request, $id, $data) {
            $managerId = DB::table('family_member_applications')->where('id', $id)->value('manager_user_id');
            if ($managerId === null) {
                throw new ApiException(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
            }
            // Match application creation lock order: manager, then application.
            DB::table('users')->where('id', $managerId)->lockForUpdate()->first();
            $application = DB::table('family_member_applications')->where('id', $id)->lockForUpdate()->first();
            if ($application === null) {
                throw new ApiException(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
            }
            if ($data['decision'] === 'approved') {
                $manager = DB::table('users')->where('id', $application->manager_user_id)->first();
                if ($manager === null || ! $manager->is_active || (int) $manager->verified_status !== 2 || $manager->FamilyUser) {
                    throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '主要會員目前不符合家庭會員資格。');
                }
                $max = (int) DB::table('family_member_settings')->where('id', 1)->value('max_members');
                $count = DB::table('family_memberships')->where('manager_user_id', $application->manager_user_id)->count();
                if ($count >= $max) {
                    throw new ApiException(403, 'FAMILY_MEMBER_LIMIT_REACHED', '已達管理員設定的上限。');
                }
                if ($count >= 1 && ! $application->payment_verified) {
                    throw new ApiException(403, 'FAMILY_PAYMENT_REQUIRED', '第二位起須先確認付款才能核准。');
                }
            }
            if ($application->status !== 'pending') {
                throw new ApiException(409, 'APPLICATION_ALREADY_REVIEWED', 'Application already reviewed.');
            }
            if ($data['decision'] === 'rejected') {
                // A rejected application must not burn a verified payment credit.
                DB::table('family_member_paid_credits')->where('used_application_id', $id)
                    ->update(['used_application_id' => null, 'updated_at' => now()]);
            }
            DB::table('family_member_applications')->where('id', $id)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
                'updated_at' => now(),
            ]);
            return ['id' => $id, 'status' => $data['decision']];
        });
        return new JsonResponse(['data' => $result]);
    }

    public function evidence(Request $request, int $id)
    {
        if (! $request->user()->is_active || ! $request->user()->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', 'Administrator approval required.');
        }
        $application = DB::table('family_member_applications')->where('id', $id)->first();
        if ($application === null) {
            throw new ApiException(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if (! Storage::disk('local')->exists($application->evidence_path)) {
            throw new ApiException(404, 'EVIDENCE_NOT_FOUND', 'Evidence not found.');
        }
        return Storage::disk('local')->download($application->evidence_path, 'relationship-evidence');
    }

    public function pending(Request $request): JsonResponse
    {
        if (! $request->user()->is_active || ! $request->user()->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', 'Administrator approval required.');
        }
        $rows = DB::table('family_member_applications')->where('status', 'pending')
            ->orderBy('id')->paginate(20, ['id', 'manager_user_id', 'member_name', 'relationship', 'payment_verified', 'created_at']);
        return new JsonResponse(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $max = DB::table('family_member_settings')->where('id', 1)->value('max_members');
        $counts = DB::table('family_memberships')->select('manager_user_id')
            ->selectRaw('COUNT(*) as family_count')->groupBy('manager_user_id')->get();
        return new JsonResponse(['data' => ['max_members' => (int) $max, 'free_members' => 1,
            'members' => $counts]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['max_members' => ['required', 'integer', 'min:1', 'max:100']]);
        DB::transaction(function () use ($data): void {
            DB::table('family_member_settings')->where('id', 1)->lockForUpdate()->first();
            $largest = (int) (DB::table('family_memberships')->selectRaw('COUNT(*) as total')
                ->groupBy('manager_user_id')->orderByDesc('total')->value('total') ?? 0);
            if ((int) $data['max_members'] < $largest) {
                throw new ApiException(409, 'FAMILY_LIMIT_BELOW_EXISTING', '上限不可低於既有家庭會員數。');
            }
            DB::table('family_member_settings')->where('id', 1)
                ->update(['max_members' => $data['max_members'], 'updated_at' => now()]);
        });
        return new JsonResponse(['data' => ['max_members' => (int) $data['max_members'], 'free_members' => 1]]);
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'manager_user_id' => ['required', 'integer', 'exists:users,id'],
            'payment_reference' => ['required', 'string', 'max:120', 'unique:family_member_paid_credits,payment_reference'],
        ]);
        $target = DB::table('users')->where('id', $data['manager_user_id'])->first();
        if ($target === null || ! $target->is_active || (int) $target->verified_status !== 2 || $target->FamilyUser) {
            throw new ApiException(403, 'MEMBER_NOT_VERIFIED', '僅能為有效的已驗證主要會員確認付款。');
        }
        $id = DB::table('family_member_paid_credits')->insertGetId([
            'manager_user_id' => $data['manager_user_id'],
            'payment_reference' => $data['payment_reference'],
            'verified_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return new JsonResponse(['data' => ['id' => $id, 'verified' => true]], 201);
    }

    private function requireAdmin(Request $request): void
    {
        if (! $request->user()->is_active || ! $request->user()->isAdmin()) {
            throw new ApiException(403, 'ADMIN_REQUIRED', 'Administrator required.');
        }
    }

}
