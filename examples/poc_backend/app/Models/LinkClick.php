<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S9 M17 raw click / open event (kept 90 days). Never stores a full IP address.
 */
class LinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'click_id', 'content_type', 'content_id', 'channel', 'source', 'medium', 'campaign',
        'device_class', 'dedupe_hash', 'is_bot', 'is_abnormal', 'stat_date', 'clicked_at', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'is_abnormal' => 'boolean',
            'stat_date' => 'date',
            'clicked_at' => 'datetime',
            'opened_at' => 'datetime',
        ];
    }
}
