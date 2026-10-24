<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value store behind App\Support\Settings. Values are JSON encoded.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value'];
}
