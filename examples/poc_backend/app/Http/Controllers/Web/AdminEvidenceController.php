<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\VerificationEvidence;
use App\Services\Verification\VerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S9 M03 evidence download for reviewers (signed, 10-minute URL + signed-in
 * admin with verifications.review, D12).
 */
class AdminEvidenceController extends Controller
{
    public function __invoke(Request $request, int $id, VerificationService $verifications): StreamedResponse
    {
        $user = $request->user();
        $evidence = VerificationEvidence::query()->findOrFail($id);
        abort_unless($user !== null && $verifications->canReadEvidence($evidence, $user) && $verifications->evidenceExists($evidence), 404);

        return Storage::disk('local')->response($evidence->path, $evidence->original_name, ['Cache-Control' => 'private, no-store']);
    }
}
