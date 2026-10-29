<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSession extends Model
{
    protected $table = 'course_sessions';

    protected $guarded = ['id'];

    public function makeups(): HasMany
    {
        return $this->hasMany(CourseMakeup::class, 'target_course_session_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }
}
