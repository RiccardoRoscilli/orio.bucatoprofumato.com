<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransaction extends Model
{
    protected $fillable = ['sale_id', 'order_id', 'action', 'request', 'response', 'error'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];
}
