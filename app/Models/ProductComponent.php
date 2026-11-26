<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una riga del contenuto di un cesto: SKU e nome del prodotto dentro, con la quantità. */
class ProductComponent extends Model
{
    protected $fillable = ['product_id', 'sku', 'name', 'qty'];

    protected $casts = ['qty' => 'float'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
