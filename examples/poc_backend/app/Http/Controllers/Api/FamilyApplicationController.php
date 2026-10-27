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
}
