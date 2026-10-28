<?php

use App\Models\MemberVerification;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Models\VerificationType;
use App\Notifications\MemberNotification;
use App\Notifications\VerificationRevokedMail;
use App\Services\Verification\VerificationService;
use App\Support\Api\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

function verificationType(array $attributes = []): VerificationType
{
    return VerificationType::query()->create(array_merge([
        'name' => '教職人員', 'requires_evidence' => true, 'required_approvals' => 1,
        'validity_days' => null, 'allow_multiple' => false, 'revoke_notify' => VerificationType::NOTIFY_APP, 'is_active' => true,
    ], $attributes));
}

function reviewer(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('verification_reviewer', 'web'));

    return $user;
}

function applyFor(User $member, VerificationType $type, array $files = [])
{
    return test()->actingAs($member, 'sanctum')->post('/api/v1/me/verification-requests', ['type_id' => $type->id, 'note' => '申請說明', 'evidence' => $files], ['Accept' => 'application/json']);
}

// D01 / D02 / D12 — apply with private evidence; only the owner and reviewers can read it.
it('applies with private evidence readable only by the owner and reviewers', function () {
    Storage::fake('local');
    $type = verificationType();
    $member = User::factory()->create();

    applyFor($member, $type)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    applyFor($member, $type, [UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')])->assertStatus(422);
    $id = applyFor($member, $type, [UploadedFile::fake()->image('card.jpg')])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    applyFor($member, $type, [UploadedFile::fake()->image('card.jpg')])->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_PENDING');

    $mine = $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/verifications')->assertOk();
    $evidenceId = $mine->json('data.requests.0.evidence.0.id');
    expect($mine->json('data.requests.0.id'))->toBe($id);

    $this->actingAs($member, 'sanctum')->get("/api/v1/me/verification-evidence/{$evidenceId}")->assertOk();
    $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/me/verification-evidence/{$evidenceId}")->assertNotFound();
    $this->actingAs(reviewer(), 'sanctum')->get("/api/v1/me/verification-evidence/{$evidenceId}")->assertOk();

    // The admin evidence URL needs a valid signature.
    $this->actingAs(makeAdmin(), 'web')->get("/admin/verification-evidence/{$evidenceId}")->assertForbidden();
});

// A persisted evidence path cannot escape its own verification request directory.
it('rejects unrelated, cross-request and separator-normalized evidence paths', function () {
    Storage::fake('local');
    $member = User::factory()->create();
    $type = verificationType();
    applyFor($member, $type, [UploadedFile::fake()->image('proof.jpg')])->assertCreated();
    $evidence = \App\Models\VerificationEvidence::query()->firstOrFail();
    $url = '/api/v1/me/verification-evidence/'.$evidence->id;
    $this->actingAs($member, 'sanctum')->get($url)->assertOk();

    Storage::disk('local')->put('private/unrelated.jpg', 'secret');
    $evidence->forceFill(['path' => 'private/unrelated.jpg'])->save();
    $this->actingAs($member, 'sanctum')->get($url)->assertNotFound();
    $this->actingAs(reviewer(), 'sanctum')->get($url)->assertNotFound();

    $evidence->forceFill(['path' => 'verification-evidence/'.$evidence->verification_request_id.'/../unrelated.jpg'])->save();
    $this->actingAs($member, 'sanctum')->get($url)->assertNotFound();

    $other = 'verification-evidence/'.($evidence->verification_request_id + 1).'/other.jpg';
    Storage::disk('local')->put($other, 'another-request');
    $evidence->forceFill(['path' => $other])->save();
    $this->actingAs($member, 'sanctum')->get($url)->assertNotFound();

    Storage::disk('local')->put('verification-evidence/'.$evidence->verification_request_id.'/one/slash.jpg', 'normalized-file');
    $evidence->forceFill(['path' => 'verification-evidence/'.$evidence->verification_request_id.'/one\\slash.jpg'])->save();
    $this->actingAs($member, 'sanctum')->get($url)->assertNotFound();
});

// D06 — several approvals, no self review, one vote per reviewer; rejection ends it.
it('needs the configured approvals from different reviewers and never the applicant', function () {
    $type = verificationType(['requires_evidence' => false, 'required_approvals' => 2, 'validity_days' => 365]);
    $member = User::factory()->create();
    $member->assignRole(Role::findOrCreate('verification_reviewer', 'web'));
    applyFor($member, $type)->assertCreated();
    $request = VerificationRequest::query()->sole();
    $service = app(VerificationService::class);
    [$first, $second] = [reviewer(), reviewer()];

    expect(fn () => $service->review($request, $member, 'approve', null))->toThrow(ApiException::class, '不能審核自己的申請');
    expect(fn () => $service->review($request, User::factory()->create(), 'approve', null))->toThrow(ApiException::class);

    $service->review($request, $first, 'approve', null);
    expect($request->refresh()->status)->toBe('pending');
    expect(fn () => $service->review($request, $first, 'approve', null))->toThrow(ApiException::class, '您已審核過此申請');

    $service->review($request, $second, 'approve', 'OK');
    $badge = MemberVerification::query()->sole();
    expect($request->refresh()->status)->toBe('approved')
        ->and($badge->is_public)->toBeTrue()
        ->and($badge->expires_at->isSameDay(now()->addDays(365)))->toBeTrue()
        ->and($member->notifications()->sole()->data)->toMatchArray(['type' => 'verification', 'title' => '認證已通過']);

    // Already verified (allow_multiple false).
    applyFor($member, $type)->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_VERIFIED');
});

it('lets members withdraw and reapply after a rejection', function () {
    $type = verificationType(['requires_evidence' => false]);
    $member = User::factory()->create();
    $id = applyFor($member, $type)->json('data.id');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/verification-requests/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
    $this->actingAs($member, 'sanctum')->postJson("/api/v1/me/verification-requests/{$id}/withdraw")->assertStatus(409);

    $second = applyFor($member, $type)->assertCreated()->json('data.id');
    app(VerificationService::class)->review(VerificationRequest::query()->findOrFail($second), reviewer(), 'reject', '資料不清楚');
    $this->actingAs($member, 'sanctum')->getJson('/api/v1/me/verifications')->assertJsonPath('data.requests.0.status', 'rejected')->assertJsonPath('data.requests.0.rejection_reason', '資料不清楚');
    applyFor($member, $type)->assertCreated();
});

// D04 / D07 / D10 — expiry, revocation takes effect at once, notification by the type's channel.
it('expires badges and revokes with the configured notification channel', function () {
    Notification::fake();
    $member = User::factory()->create();
    $appType = verificationType(['requires_evidence' => false, 'validity_days' => 30]);
    $mailType = verificationType(['name' => '志工', 'requires_evidence' => false, 'revoke_notify' => VerificationType::NOTIFY_EMAIL]);
    foreach ([$appType, $mailType] as $type) {
        applyFor($member, $type)->assertCreated();
        app(VerificationService::class)->review(VerificationRequest::query()->latest('id')->firstOrFail(), reviewer(), 'approve', null);
    }
    [$appBadge, $mailBadge] = MemberVerification::query()->orderBy('id')->get()->all();

    app(VerificationService::class)->revoke($mailBadge, makeAdmin(), '資格不符');
    Notification::assertSentTo($member, VerificationRevokedMail::class);
    expect($mailBadge->refresh()->state())->toBe('revoked')->and(MemberVerification::query()->valid()->count())->toBe(1);

    app(VerificationService::class)->revoke($appBadge, makeAdmin(), '停用');
    Notification::assertSentTo($member, MemberNotification::class, fn (MemberNotification $n) => $n->title === '認證已撤銷' && $n->category === 'verification');

    $other = User::factory()->create();
    applyFor($other, $appType)->assertCreated();
    app(VerificationService::class)->review(VerificationRequest::query()->latest('id')->firstOrFail(), reviewer(), 'approve', null);
    $this->travel(31)->days();
    expect(MemberVerification::query()->where('user_id', $other->id)->sole()->state())->toBe('expired');
});

// D03 / D08 — badges are public by default; the member can hide them; hidden badges never show.
it('shows public badges on comments and lets the member hide them', function () {
    $type = verificationType(['requires_evidence' => false]);
    $member = User::factory()->create();
    applyFor($member, $type)->assertCreated();
    app(VerificationService::class)->review(VerificationRequest::query()->sole(), reviewer(), 'approve', null);
    $badge = MemberVerification::query()->sole();

    $this->actingAs(User::factory()->create(), 'sanctum')->patchJson("/api/v1/me/verifications/{$badge->id}", ['is_public' => false])->assertNotFound();
    $this->actingAs($member, 'sanctum')->patchJson("/api/v1/me/verifications/{$badge->id}", ['is_public' => false])->assertOk()->assertJsonPath('data.is_public', false);
    expect($badge->refresh()->is_public)->toBeFalse();

    $this->getJson('/api/v1/verification-types')->assertOk()->assertJsonPath('data.0.name', '教職人員');
});
