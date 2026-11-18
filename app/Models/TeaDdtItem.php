<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeaDdtItem extends Model
{
    protected $fillable = ['tea_ddt_id', 'product_id', 'sku', 'name', 'pcs_per_box', 'qty_shipped', 'qty_received', 'received_at', 'received_by'];

    protected $casts = ['pcs_per_box' => 'integer', 'qty_shipped' => 'float', 'qty_received' => 'float', 'received_at' => 'datetime'];

    public function ddt(): BelongsTo
    {
        return $this->belongsTo(TeaDdt::class, 'tea_ddt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Scatole spedite (o ricevute) dai pezzi. */
    public function boxes(?float $qty): ?float
    {
        return $qty === null ? null : round($qty / max(1, $this->pcs_per_box), 3);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
