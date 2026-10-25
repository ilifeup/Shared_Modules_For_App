<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S9 M17 daily aggregate (kept 24 months).
 */
class LinkDailyStat extends Model
{
    protected $fillable = ['stat_date', 'content_type', 'content_id', 'source', 'device_class', 'total_clicks', 'dedup_clicks', 'opens', 'bot_excluded', 'abnormal_excluded'];

    protected function casts(): array
    {
        return ['stat_date' => 'date'];
    }
}
