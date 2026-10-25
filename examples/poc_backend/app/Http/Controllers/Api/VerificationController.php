<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberVerification;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequest;
use App\Models\VerificationType;
use App\Services\Verification\VerificationService;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S9 M03 member side: types, apply with evidence, status, badges. Reviews happen
 * in Filament (authorised reviewers).
 */
class VerificationController extends Controller
{
    public function __construct(private readonly VerificationService $verifications) {}

    /**
     * GET /api/v1/verification-types
     */
    public function types(): JsonResponse
    {
        return new JsonResponse(['data' => VerificationType::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (VerificationType $t) => [
                'id' => (string) $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'requires_evidence' => $t->requires_evidence,
                'required_approvals' => $t->required_approvals,
                'validity_days' => $t->validity_days,
                'allow_multiple' => $t->allow_multiple,
            ])->all()]);
    }

    /**
     * GET /api/v1/me/verifications — my applications and badges.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $requests = VerificationRequest::query()->with('type', 'evidence', 'reviews')->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get();
        $badges = MemberVerification::query()->with('type')->where('user_id', $user->id)->orderByDesc('granted_at')->get();

        return new JsonResponse(['data' => [
            'requests' => $requests->map(fn (VerificationRequest $r) => [
                'id' => (string) $r->id,
                'type' => ['id' => (string) $r->verification_type_id, 'name' => $r->type->name],
                'status' => $r->status,
                'note' => $r->note,
                'rejection_reason' => $r->rejection_reason,
                'approvals' => $r->reviews->where('decision', 'approve')->count(),
                'required_approvals' => $r->type->required_approvals,
                'submitted_at' => $r->created_at?->toIso8601ZuluString(),
                'decided_at' => $r->decided_at?->toIso8601ZuluString(),
                'evidence' => $r->evidence->map(fn (VerificationEvidence $e) => ['id' => (string) $e->id, 'name' => $e->original_name, 'mime' => $e->mime, 'size' => $e->size])->values()->all(),
            ])->values()->all(),
            'badges' => $badges->map(fn (MemberVerification $b) => self::badgeData($b))->values()->all(),
        ]]);
    }

    /**
     * POST /api/v1/me/verification-requests (multipart: type_id, note?, evidence[])
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_id' => ['required', 'integer', 'min:1'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'evidence' => ['sometimes', 'array', 'max:'.VerificationService::MAX_FILES],
            'evidence.*' => ['file', 'max:'.VerificationService::MAX_KB, 'mimetypes:'.implode(',', VerificationService::MIMES)],
        ]);
        $type = VerificationType::query()->findOrFail((int) $data['type_id']);
        $created = $this->verifications->apply($request->user(), $type, $data['note'] ?? null, array_values($request->file('evidence', [])));

        return new JsonResponse(['data' => ['id' => (string) $created->id, 'status' => $created->status]], 201);
    }

    /**
     * POST /api/v1/me/verification-requests/{id}/withdraw
     */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $item = VerificationRequest::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $this->verifications->withdraw($item, $request->user());

        return new JsonResponse(['data' => ['id' => (string) $item->id, 'status' => VerificationRequest::WITHDRAWN]]);
    }

    /**
     * GET /api/v1/me/verification-evidence/{id} — own evidence file only (D12).
     */
    public function evidence(Request $request, int $id): StreamedResponse
    {
        $evidence = VerificationEvidence::query()->findOrFail($id);
        if (! $this->verifications->canReadEvidence($evidence, $request->user()) || ! $this->verifications->evidenceExists($evidence)) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此檔案。');
        }

        return Storage::disk('local')->response($evidence->path, $evidence->original_name, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * PATCH /api/v1/me/verifications/{id} {is_public} — show or hide my badge (D03, D08).
     */
    public function updateBadge(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);
        $badge = MemberVerification::query()->with('type')->where('user_id', $request->user()->id)->findOrFail($id);

        return new JsonResponse(['data' => self::badgeData($this->verifications->setBadgePublic($badge, $request->user(), (bool) $data['is_public']))]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function badgeData(MemberVerification $b): array
    {
        return [
            'id' => (string) $b->id,
            'type' => ['id' => (string) $b->verification_type_id, 'name' => $b->type->name],
            'state' => $b->state(),
            'is_public' => $b->is_public,
            'granted_at' => $b->granted_at->toIso8601ZuluString(),
            'expires_at' => $b->expires_at?->toIso8601ZuluString(),
            'revoked_at' => $b->revoked_at?->toIso8601ZuluString(),
        ];
    }
}
