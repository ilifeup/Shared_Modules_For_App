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
                'created_at' => now(),
                'updated_at' => now(),
            ]);
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
            $application = DB::table('family_member_applications')->where('id', $id)->lockForUpdate()->first();
            if ($application === null) {
                throw new ApiException(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
            }
            if ($data['decision'] === 'approved') {
                $count = DB::table('family_memberships')->where('manager_user_id', $application->manager_user_id)->count();
                if ($count >= 1 && ! $application->payment_verified) {
                    throw new ApiException(403, 'FAMILY_PAYMENT_REQUIRED', '第二位起須先確認付款才能核准。');
                }
            }
            if ($application->status !== 'pending') {
                throw new ApiException(409, 'APPLICATION_ALREADY_REVIEWED', 'Application already reviewed.');
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
            ->orderBy('id')->paginate(20, ['id', 'manager_user_id', 'member_name', 'relationship', 'created_at']);
        return new JsonResponse(['data' => $rows->items(), 'meta' => ['total' => $rows->total()]]);
    }

}
