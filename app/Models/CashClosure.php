<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashClosure extends Model
{
    protected $fillable = ['user_id', 'day', 'vne_data', 'orio_cash_cents', 'orio_card_cents', 'orio_pos_cents'];

    protected $casts = [
        'day' => 'date',
        'vne_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
