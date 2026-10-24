<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * S9 M19 member bookmark; content is referenced by type + id (no FK), so a
 * placeholder stays after the content is removed (D21).
 */
class Bookmark extends Model
{
    public const UPDATED_AT = null;

    public const MAX_PER_MEMBER = 1000;

    protected $fillable = ['user_id', 'content_type', 'content_id'];
}
