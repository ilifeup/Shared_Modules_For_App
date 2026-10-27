<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\VerificationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // ids are strings in the shared contract (packages/flutter/shared_core MemberProfile).
            'id' => (string) $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name ?? $this->name,
            // S8 M01: public nickname (likes / comments) and privacy.
            'nickname' => $this->nickname,
            'privacy' => ['show_in_like_list' => (bool) $this->show_in_like_list],
            'email' => $this->email,
            'phone' => $this->phone_e164,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'verified_status' => (int) $this->verified_status,
            // S3 A02: whether the current verification policy grants full member access.
            'verification' => app(VerificationPolicy::class)->describe($this->resource),
            // S3 A03: avatar is fetched from GET /api/v1/me/avatar (owner only).
            'has_avatar' => $this->avatar_path !== null,
            // S3 A03: old members may lack required fields; the App guides them to complete them.
            'profile' => [
                'complete' => $this->resource->missingProfileFields() === [],
                'missing' => $this->resource->missingProfileFields(),
            ],
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
