<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPES = [
        'carico' => 'Carico merce',
        'vendita' => 'Vendita',
        'storno' => 'Vendita annullata',
        'rettifica' => 'Rettifica inventario',
    ];

    protected $fillable = ['product_id', 'type', 'qty', 'stock_after', 'sale_id', 'user_id', 'note'];

    protected $casts = [
        'qty' => 'float',
        'stock_after' => 'float',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
