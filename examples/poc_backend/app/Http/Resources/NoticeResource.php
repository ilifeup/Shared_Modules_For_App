<?php

namespace App\Http\Resources;

use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notice
 */
class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Field names/types follow packages/flutter/shared_core NoticeDto (S1 fields unchanged).
            'id' => (string) $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601ZuluString(),
            // S3 M06 additions.
            'publish_ends_at' => $this->publish_ends_at?->toIso8601ZuluString(),
            'is_pinned' => (bool) $this->is_pinned,
            'audience' => $this->audience,
            'category' => $this->category === null ? null : [
                'id' => (string) $this->category->id,
                'name' => $this->category->name,
            ],
            // Only present for member requests (withCount / read flag set by the controller).
            'is_read' => $this->when(isset($this->resource->is_read), fn () => (bool) $this->resource->is_read),
        ];
    }
}
