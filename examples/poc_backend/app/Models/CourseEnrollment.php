<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CourseEnrollment extends Model
{
    protected $table = 'course_enrollments';

    protected $guarded = ['id'];

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'booking_id')
            ->where('booking_type', '02');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booker_user_id');
    }
}
