<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chiusura giornata: chiusura fiscale RT (report Z) e chiusura della cassa automatica VNE,
 * con gli incassi registrati da orio nello stesso periodo (dalla chiusura precedente).
 */
class CashClosure extends Model
{
    protected $fillable = [
        'user_id', 'day', 'period_from', 'fiscal_z_number', 'fiscal_closed_at',
        'vne_data', 'vne_closed_at', 'vne_error', 'orio_cash_cents', 'orio_card_cents', 'orio_pos_cents',
    ];

    protected $casts = [
        'day' => 'date',
        'period_from' => 'datetime',
        'fiscal_closed_at' => 'datetime',
        'vne_closed_at' => 'datetime',
        'vne_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
