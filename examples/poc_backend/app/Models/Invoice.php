<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';
    protected $guarded = ['id'];
    protected $casts = ['invoice_amount' => 'decimal:2', 'invoice_datetime' => 'datetime', 'payment_due_at' => 'datetime'];
}
