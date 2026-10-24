<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * S9 M15 display block configured per App (D08, D13).
 */
class MarketingPlacement extends Model
{
    protected $fillable = ['code', 'name', 'max_items', 'is_active'];

    protected function casts(): array
    {
        return ['max_items' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsToMany<MarketingCampaign, $this>
     */
    public function campaigns(): BelongsToMany
    {
        return $this->belongsToMany(MarketingCampaign::class, 'marketing_campaign_placement');
    }
}
