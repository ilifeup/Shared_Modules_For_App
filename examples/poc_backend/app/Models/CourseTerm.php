<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseTerm extends Model
{
    protected $table = 'course_terms';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'booking_closes_at' => 'datetime',
        ];
    }
}
