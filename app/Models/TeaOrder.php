<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ordine di riassortimento del negozio inviato a TEA (teaerp). */
class TeaOrder extends Model
{
    public const STATUSES = ['bozza' => 'Bozza', 'inviato' => 'Inviato a TEA', 'errore' => 'Non inviato'];

    protected $fillable = [
        'reference', 'status', 'tea_increment_id', 'tea_status', 'subtotal_cents', 'tax_cents', 'total_cents',
        'notes', 'error', 'response', 'user_id', 'sent_at', 'status_checked_at',
    ];

    protected $casts = [
        'response' => 'array',
        'sent_at' => 'datetime',
        'status_checked_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(TeaOrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
