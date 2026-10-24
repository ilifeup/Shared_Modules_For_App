<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * S9 M16 single-level tag; one per normalized name (D05). Colour is automatic
 * (stable palette by id) unless an admin set one (D21 revised).
 */
class Tag extends Model
{
    /** Readable on white and dark text alike; picked by id so it never changes per request. */
    public const PALETTE = [
        '#1E88E5', '#43A047', '#E53935', '#8E24AA', '#FB8C00', '#00897B',
        '#3949AB', '#C0CA33', '#D81B60', '#6D4C41', '#00ACC1', '#7CB342',
    ];

    public const MAX_PER_CONTENT = 10;

    protected $fillable = ['name', 'color', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->name = trim(preg_replace('/\s+/u', ' ', (string) $tag->name) ?? '');
            $tag->normalized_name = self::normalize($tag->name);
        });
    }

    /** NFKC (full/half width), trimmed, single spaces, lower case. */
    public static function normalize(string $name): string
    {
        $normalized = class_exists(Normalizer::class) ? (Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name) : $name;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $normalized) ?? ''));
    }

    public function displayColor(): string
    {
        return $this->color ?? self::PALETTE[($this->id ?? 0) % count(self::PALETTE)];
    }

    public function usageCount(): int
    {
        return DB::table('taggables')->where('tag_id', $this->id)->count();
    }
}
