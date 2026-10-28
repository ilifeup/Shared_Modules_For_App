<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseTerm extends Model
{
    protected $table = 'course_terms';
    protected $guarded = ['id'];

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CourseSession::class, 'course_term_id');
    }

    public function enrollments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\CourseEnrollment::class, 'course_term_id');
    }

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'booking_closes_at' => 'datetime',
        ];
    }
}
