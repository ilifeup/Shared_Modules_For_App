<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseMakeup extends Model
{
    protected $table = 'course_makeups';

    protected $guarded = ['id'];

    public function targetSession(): BelongsTo
    {
        return $this->belongsTo(CourseSession::class, 'target_course_session_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(EventBookingGroup::class, 'event_booking_group_id');
    }
}
