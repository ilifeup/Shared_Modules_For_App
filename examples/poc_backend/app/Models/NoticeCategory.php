<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NoticeCategory extends Model
{
    protected $fillable = ['name', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['sort' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Notice, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }
}
