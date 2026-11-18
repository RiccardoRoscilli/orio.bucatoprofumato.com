<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeaOrderItem extends Model
{
    protected $fillable = ['tea_order_id', 'product_id', 'sku', 'name', 'qty', 'boxes', 'stock_at_order', 'price_cents', 'row_total_cents'];

    protected $casts = ['stock_at_order' => 'float'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
