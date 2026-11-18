<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** DDT di TEA per un ordine di riassortimento, con le righe da spuntare all'arrivo della merce. */
class TeaDdt extends Model
{
    protected $fillable = ['tea_order_id', 'ddt_number', 'issued_at', 'confirmed_at', 'confirmed_by'];

    protected $casts = ['issued_at' => 'date', 'confirmed_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TeaOrder::class, 'tea_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TeaDdtItem::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isReceived(): bool
    {
        return $this->items->every(fn (TeaDdtItem $i) => $i->qty_received !== null);
    }
}
