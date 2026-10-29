<?php

namespace App\Services\Verification;

use App\Models\AuditEvent;
use App\Models\MemberVerification;
use App\Models\User;
use App\Models\VerificationEvidence;
use App\Models\VerificationRequest;
use App\Models\VerificationReview;
use App\Models\VerificationType;
use App\Notifications\VerificationRevokedMail;
use App\Services\MemberNotifier;
use App\Support\Api\ApiException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * S9 M03 identity verification: apply (with private evidence when the type needs
 * it), multi-reviewer approval (D06, no self review, one vote each), badges with
 * optional validity (D04), revocation with the type's notification channel (D07, D10).
 * Verification is separate from roles and admin permissions.
 */
class VerificationService
{
    public const MAX_FILES = 5;

    public const MAX_KB = 5120;

    public const MIMES = ['image/jpeg', 'image/png', 'application/pdf'];

    public function __construct(private readonly MemberNotifier $notifier) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function apply(User $member, VerificationType $type, ?string $note, array $files): VerificationRequest
    {
        if (! $type->is_active) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此認證類型。');
        }
        if (! $type->allow_multiple && MemberVerification::query()->valid()->where('user_id', $member->id)->where('verification_type_id', $type->id)->exists()) {
            throw new ApiException(409, 'ALREADY_VERIFIED', '您已有此有效認證。');
        }
        if (VerificationRequest::query()->where('user_id', $member->id)->where('verification_type_id', $type->id)->where('status', VerificationRequest::PENDING)->exists()) {
            throw new ApiException(409, 'REQUEST_PENDING', '您已有審核中的申請。');
        }
        if ($type->requires_evidence && $files === []) {
            throw new ApiException(422, 'VALIDATION_FAILED', '此認證需要上傳證明文件。', ['evidence' => ['請上傳證明文件。']]);
        }
        if (count($files) > self::MAX_FILES) {
            throw new ApiException(422, 'VALIDATION_FAILED', '最多上傳 '.self::MAX_FILES.' 個檔案。', ['evidence' => ['最多上傳 '.self::MAX_FILES.' 個檔案。']]);
        }
        foreach ($files as $file) {
            if (! $file->isValid() || ! in_array($file->getMimeType(), self::MIMES, true) || $file->getSize() > self::MAX_KB * 1024) {
                throw new ApiException(422, 'VALIDATION_FAILED', '證明文件需為 JPG、PNG 或 PDF，單檔 5 MB 以內。', ['evidence' => ['證明文件需為 JPG、PNG 或 PDF，單檔 5 MB 以內。']]);
            }
        }

        $request = DB::transaction(function () use ($member, $type, $note, $files): VerificationRequest {
            $request = VerificationRequest::query()->create([
                'user_id' => $member->id,
                'verification_type_id' => $type->id,
                'status' => VerificationRequest::PENDING,
                'note' => $note !== null ? mb_substr(trim($note), 0, 1000) : null,
            ]);
            foreach ($files as $file) {
                $path = $file->store('verification-evidence/'.$request->id, 'local');
                VerificationEvidence::query()->create([
                    'verification_request_id' => $request->id,
                    'path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ]);
            }

            return $request;
        });

        AuditEvent::record('verification.applied', $member->id, $request, ['type_id' => $type->id, 'files' => count($files)]);

        return $request;
    }

    public function withdraw(VerificationRequest $request, User $member): void
    {
        if ($request->user_id !== $member->id || $request->status !== VerificationRequest::PENDING) {
            throw new ApiException(409, 'REQUEST_NOT_PENDING', '此申請已不在審核中。');
        }
        $request->forceFill(['status' => VerificationRequest::WITHDRAWN, 'decided_at' => now()])->save();
        AuditEvent::record('verification.withdrawn', $member->id, $request);
    }

    /**
     * One vote per reviewer; approved once the type's required approvals are reached
     * (default one authorised admin, D01). Any rejection rejects the application.
     */
    public function review(VerificationRequest $request, User $reviewer, string $decision, ?string $comment): VerificationRequest
    {
        if (! $reviewer->can('verifications.review')) {
            throw new ApiException(403, 'FORBIDDEN', '沒有審核權限。');
        }
        if ($reviewer->id === $request->user_id) {
            throw new ApiException(403, 'SELF_REVIEW', '不能審核自己的申請。');
        }
        if (! in_array($decision, [VerificationReview::APPROVE, VerificationReview::REJECT], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', '審核結果只能是核准或拒絕。');
        }

        $granted = null;
        try {
            $result = DB::transaction(function () use ($request, $reviewer, $decision, $comment, &$granted): VerificationRequest {
                /** @var VerificationRequest $locked */
                $locked = VerificationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== VerificationRequest::PENDING) {
                    throw new ApiException(409, 'REQUEST_NOT_PENDING', '此申請已審核完成。');
                }

                VerificationReview::query()->create([
                    'verification_request_id' => $locked->id,
                    'reviewer_id' => $reviewer->id,
                    'decision' => $decision,
                    'comment' => $comment !== null ? mb_substr($comment, 0, 255) : null,
                ]);

                if ($decision === VerificationReview::REJECT) {
                    $locked->forceFill(['status' => VerificationRequest::REJECTED, 'rejection_reason' => $comment !== null ? mb_substr($comment, 0, 255) : null, 'decided_at' => now()])->save();

                    return $locked;
                }

                $approvals = VerificationReview::query()->where('verification_request_id', $locked->id)->where('decision', VerificationReview::APPROVE)->count();
                $type = $locked->type()->firstOrFail();
                if ($approvals >= max(1, $type->required_approvals)) {
                    $locked->forceFill(['status' => VerificationRequest::APPROVED, 'decided_at' => now()])->save();
                    $granted = MemberVerification::query()->create([
                        'user_id' => $locked->user_id,
                        'verification_type_id' => $type->id,
                        'verification_request_id' => $locked->id,
                        'granted_at' => now(),
                        'expires_at' => $type->validity_days !== null ? now()->addDays($type->validity_days) : null,
                        'is_public' => true,
                    ]);
                }

                return $locked;
            });
        } catch (QueryException $e) {
            if (VerificationReview::query()->where('verification_request_id', $request->id)->where('reviewer_id', $reviewer->id)->exists()) {
                throw new ApiException(409, 'ALREADY_REVIEWED', '您已審核過此申請。');
            }
            throw $e;
        }

        AuditEvent::record('verification.reviewed', $reviewer->id, $result, ['decision' => $decision, 'status' => $result->status]);
        if ($result->status !== VerificationRequest::PENDING) {
            $this->tell($result->user()->first(), $result->status === VerificationRequest::APPROVED ? '認證已通過' : '認證未通過',
                '您申請的「'.$result->type()->value('name').'」'.($result->status === VerificationRequest::APPROVED ? '已通過審核。' : '未通過審核，可修正後重新申請。'));
        }

        return $result;
    }

    /** Takes effect at once and keeps the record (D07); notifies per type (D10). */
    public function revoke(MemberVerification $verification, User $admin, string $reason): void
    {
        if (! $admin->is_active || ! $admin->can('verifications.review')) {
            throw new ApiException(403, 'FORBIDDEN', '沒有撤銷認證的權限。');
        }
        if ($verification->revoked_at !== null) {
            return;
        }
        $verification->forceFill(['revoked_at' => now(), 'revoked_by' => $admin->id, 'revoke_reason' => mb_substr($reason, 0, 255)])->save();
        AuditEvent::record('verification.revoked', $admin->id, $verification, ['reason' => $reason]);

        $type = $verification->type()->firstOrFail();
        $member = $verification->user()->first();
        if ($member === null) {
            return;
        }
        try {
            match ($type->revoke_notify) {
                VerificationType::NOTIFY_APP => $this->notifier->send($member, '認證已撤銷', '您的「'.$type->name.'」認證已撤銷。原因：'.$reason, 'verification'),
                VerificationType::NOTIFY_EMAIL => $member->email !== null ? $member->notify(new VerificationRevokedMail($type->name, $reason)) : null,
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('verification revoke notification failed', ['error' => class_basename($e)]);
        }
    }

    public function setBadgePublic(MemberVerification $verification, User $member, bool $public): MemberVerification
    {
        if ($verification->user_id !== $member->id) {
            throw new ApiException(404, 'NOT_FOUND', '找不到此認證。');
        }
        $verification->forceFill(['is_public' => $public])->save();

        return $verification;
    }

    /** The owner and authorised reviewers only (D12). */
    public function canReadEvidence(VerificationEvidence $evidence, User $user): bool
    {
        $owner = $evidence->request()->value('user_id');

        return $owner === $user->id || ($user->is_active && $user->can('verifications.review'));
    }

    public function evidenceExists(VerificationEvidence $evidence): bool
    {
        // Both the member API and signed admin download use this guard.
        // Never trust a persisted path to reference another local-disk file.
        $path = $evidence->path;
        $prefix = 'verification-evidence/'.$evidence->verification_request_id.'/';

        return is_string($path)
            && str_starts_with($path, $prefix)
            && strlen($path) > strlen($prefix)
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && Storage::disk('local')->exists($path);
    }

    private function tell(?User $member, string $title, string $body): void
    {
        if ($member === null) {
            return;
        }
        try {
            $this->notifier->send($member, $title, $body, 'verification');
        } catch (Throwable $e) {
            Log::warning('verification notification failed', ['error' => class_basename($e)]);
        }
    }
}
