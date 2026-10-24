<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * M02 member level (一般 / VIP). Grants member benefits only — never admin rights.
 *
 * @property string $code
 * @property string $name
 * @property string|null $benefits
 * @property int $rank
 * @property bool $is_default
 */
class MembershipLevel extends Model
{
    public const GENERAL = 'general';

    public const VIP = 'vip';

    protected $fillable = ['name', 'benefits'];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public static function default(): self
    {
        return self::query()->where('is_default', true)->orderBy('rank')->firstOrFail();
    }

    /**
     * @return HasMany<MemberLevelAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(MemberLevelAssignment::class);
    }
}
