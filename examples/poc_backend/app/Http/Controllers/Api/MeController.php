<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\MemberResource;
use App\Models\AuditEvent;
use App\Services\AvatarService;
use App\Services\ContactChangeService;
use App\Support\Api\ApiException;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeController extends Controller
{
    public function __construct(
        private readonly AvatarService $avatars,
        private readonly ContactChangeService $contacts,
    ) {}

    /**
     * GET /api/v1/me and GET /api/v1/me/profile (shared contract path)
     */
    public function show(Request $request): MemberResource
    {
        return new MemberResource($request->user());
    }

    /**
     * PATCH /api/v1/me/profile — members can change their own name and display name.
     * Email and phone have their own verified flows; other fields are ignored.
     */
    public function update(UpdateProfileRequest $request): MemberResource
    {
        $user = $request->user();
        $changes = array_filter([
            'name' => $request->has('name') ? trim($request->string('name')->toString()) : null,
            'display_name' => $request->has('display_name') ? trim($request->string('display_name')->toString()) : null,
            'nickname' => $request->has('nickname') ? trim($request->string('nickname')->toString()) : null,
            'show_in_like_list' => $request->has('show_in_like_list') ? $request->boolean('show_in_like_list') : null,
        ], fn ($value) => $value !== null);

        $user->update($changes);
        AuditEvent::record('member.profile_updated', $user->id, $user, ['fields' => array_keys($changes)]);

        return new MemberResource($user->refresh());
    }

    /**
     * POST /api/v1/me/email — add or change the email (current password required).
     */
    public function changeEmail(Request $request): MemberResource
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $this->contacts->changeEmail($user, (string) $request->input('email'), (string) $request->input('password'));

        return new MemberResource($user->refresh());
    }

    /**
     * POST /api/v1/me/phone/send-otp — code to the NEW number (current password required).
     */
    public function sendPhoneChangeCode(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $this->contacts->sendPhoneCode($request->user(), $this->phone((string) $request->input('phone')), (string) $request->input('password'));

        return new JsonResponse(['data' => ['message' => '驗證碼已發送至新手機號碼。']]);
    }

    /**
     * POST /api/v1/me/phone/verify — the number is saved only after this succeeds.
     */
    public function confirmPhoneChange(Request $request): MemberResource
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();
        $this->contacts->confirmPhone($user, $this->phone((string) $request->input('phone')), (string) $request->input('code'));

        return new MemberResource($user->refresh());
    }

    /**
     * POST /api/v1/me/avatar (multipart field "avatar")
     */
    public function uploadAvatar(Request $request): MemberResource
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'avatar.image' => '請上傳 JPG、PNG 或 WebP 圖片。',
            'avatar.mimes' => '請上傳 JPG、PNG 或 WebP 圖片。',
            'avatar.max' => '圖片不可超過 5 MB。',
        ]);

        $user = $request->user();
        $this->avatars->store($user, $request->file('avatar'));

        return new MemberResource($user->refresh());
    }

    /**
     * GET /api/v1/me/avatar — the owner's own avatar only (no public URL).
     */
    public function avatar(Request $request): StreamedResponse
    {
        $user = $request->user();

        if (! $this->avatars->exists($user)) {
            throw new ApiException(404, 'NOT_FOUND', '尚未設定頭像。');
        }

        return Storage::disk($this->avatars->disk())->response($user->avatar_path, 'avatar.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * DELETE /api/v1/me/avatar
     */
    public function deleteAvatar(Request $request): MemberResource
    {
        $user = $request->user();
        $this->avatars->delete($user);

        return new MemberResource($user->refresh());
    }

    private function phone(string $input): string
    {
        $phone = Phone::normalize($input);

        if ($phone === null) {
            throw new ApiException(422, 'VALIDATION_FAILED', '請輸入台灣手機號碼，例如 0912345678。', [
                'phone' => ['請輸入台灣手機號碼，例如 0912345678。'],
            ]);
        }

        return $phone;
    }
}
