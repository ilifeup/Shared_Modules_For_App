<?php

namespace App\Http\Resources;

use App\Models\User;
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
            'email' => $this->email,
            'phone' => $this->phone_e164,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
