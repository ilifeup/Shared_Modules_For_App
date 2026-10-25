<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArticleAccessRule extends Model
{
    public const TYPE_LEVEL = 'level';

    public const TYPE_ROLE = 'role';

    public $timestamps = false;

    protected $fillable = ['article_id', 'type', 'value'];
}
