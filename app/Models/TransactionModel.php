<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionModel extends Model
{
    use HasFactory;
    protected $table = 'transactions';
    protected $fillable = [
        'amount',
        'gateway_id',
        'gateway_url',
        'entered_amount',
        'from_currency',
        'to_currency',
        'email',
        'status',
    ];
}
