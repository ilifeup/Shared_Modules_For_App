<?php

namespace App\Models;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M07 audit trail. Append-only from the application: there is no update or delete
 * path in the API or Filament. Never pass passwords, OTPs, tokens or reset links
 * in $details.
 */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /** Keys that must never be stored, even if a caller passes them by mistake. */
    private const FORBIDDEN_DETAIL_KEYS = ['password', 'password_confirmation', 'code', 'otp', 'token', 'plain_text_token', 'reset_token'];

    /** @var list<string> */
    protected $fillable = ['event', 'actor_id', 'subject_type', 'subject_id', 'result', 'details', 'request_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(
        string $event,
        int|string|null $actorId = null,
        ?Model $subject = null,
        array $details = [],
        string $result = 'success',
    ): void {
        $request = request();

        static::query()->create([
            'event' => $event,
            'actor_id' => $actorId !== null ? (int) $actorId : null,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'result' => $result,
            'details' => $details === [] ? null : array_diff_key($details, array_flip(self::FORBIDDEN_DETAIL_KEYS)),
            'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
