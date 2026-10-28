<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseTerm extends Model
{
    protected $table = 'course_terms';

    protected $guarded = ['id'];

    public function sessions(): HasMany
    {
        return $this->hasMany(CourseSession::class, 'course_term_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class, 'course_term_id');
    }

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'booking_closes_at' => 'datetime',
        ];
    }
}
