<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * S9 M15 campaign. Stored status: draft | published | disabled; the displayed
 * state (草稿 / 已排程 / 進行中 / 已結束 / 停用, D10) is derived from the server clock.
 *
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class MarketingCampaign extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const DISABLED = 'disabled';

    public const TARGET_TYPES = ['article', 'event', 'notice', 'url'];

    protected $fillable = ['title', 'summary', 'image_path', 'button_text', 'target_type', 'target_id', 'target_url', 'status', 'priority', 'starts_at', 'ends_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'target_id' => 'integer',
        ];
    }

    /** draft | scheduled | active | ended | disabled */
    public function state(?Carbon $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->status === self::DRAFT => 'draft',
            $this->status === self::DISABLED => 'disabled',
            $this->starts_at !== null && $now->lt($this->starts_at) => 'scheduled',
            $this->ends_at !== null && $now->gte($this->ends_at) => 'ended',
            default => 'active',
        };
    }

    /**
     * @return BelongsToMany<MarketingPlacement, $this>
     */
    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(MarketingPlacement::class, 'marketing_campaign_placement');
    }
}
