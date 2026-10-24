<?php

namespace App\Models;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['event', 'actor_id', 'subject_type', 'subject_id', 'request_id'];

    /**
     * Records an event. Only identifiers are stored, never request payloads.
     */
    public static function record(string $event, int|string|null $actorId = null, ?Model $subject = null): void
    {
        $request = request();

        static::query()->create([
            'event' => $event,
            'actor_id' => $actorId !== null ? (int) $actorId : null,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'request_id' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
        ]);
    }
}
