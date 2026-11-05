<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VneTransaction extends Model
{
    protected $fillable = ['sale_id', 'vne_id', 'tipo', 'request', 'response', 'error'];

    protected $casts = [
        'request' => 'array',
        'response' => 'array',
    ];
}
