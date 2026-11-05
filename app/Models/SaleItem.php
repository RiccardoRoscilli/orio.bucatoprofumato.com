<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = ['product_id', 'sku', 'description', 'qty', 'unit_price_cents', 'vat_rate'];

    protected $casts = [
        'qty' => 'float',
        'unit_price_cents' => 'integer',
        'vat_rate' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lineTotalCents(): int
    {
        return (int) round($this->qty * $this->unit_price_cents);
    }
}
