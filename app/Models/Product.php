<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku', 'ean', 'name', 'format', 'category', 'categories', 'image_url', 'short_description', 'description',
        'site_price_cents', 'price_cents', 'vat_rate', 'active', 'sold_in_shop', 'shop_only', 'in_stock', 'synced_at',
    ];

    protected $casts = [
        'shop_only' => 'boolean',
        'site_price_cents' => 'integer',
        'price_cents' => 'integer',
        'vat_rate' => 'integer',
        'active' => 'boolean',
        'sold_in_shop' => 'boolean',
        'in_stock' => 'boolean',
        'stock_qty' => 'float',
        'synced_at' => 'datetime',
    ];

    /**
     * Cerca per codice a barre su EAN e SKU (alcuni prodotti hanno l'EAN come SKU e il campo
     * ean vuoto), ignorando gli zeri iniziali: sulle bottigliette il codice e' stampato male e
     * lo scanner a volte legge l'EAN-13 senza lo zero iniziale (o con uno in piu').
     */
    public function scopeWhereBarcode(Builder $query, string $code): Builder
    {
        $stripped = ltrim($code, '0');
        $variants = $stripped === ''
            ? [$code]
            : array_unique([$code, $stripped, '0' . $stripped, '00' . $stripped, '000' . $stripped]);

        return $query->where(fn ($q) => $q->whereIn('ean', $variants)->orWhereIn('sku', $variants));
    }

    /** Vendibili in cassa: attivi sul sito e scelti dalla titolare per il negozio. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('active', true)->where('sold_in_shop', true);
    }

    /** Prodotti il cui prezzo in cassa e' diverso da quello attuale sul sito. */
    public function scopePriceToReview(Builder $query): Builder
    {
        return $query->whereNotNull('site_price_cents')->whereColumn('price_cents', '!=', 'site_price_cents');
    }

    public function hasPriceToReview(): bool
    {
        return $this->site_price_cents !== null && $this->price_cents !== $this->site_price_cents;
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Giacenza da mostrare: intera se non ha decimali ("3", "1,5"). */
    public function stockLabel(): string
    {
        return rtrim(rtrim(number_format($this->stock_qty, 3, ',', '.'), '0'), ',');
    }

    public function toCartItem(): array
    {
        return [
            'product_id' => $this->id,
            'sku' => $this->sku,
            'ean' => $this->ean,
            'description' => trim($this->name),
            'format' => $this->format,
            'image_url' => $this->image_url,
            'price' => number_format($this->price_cents / 100, 2, ',', ''),
            'vat_rate' => $this->vat_rate,
            'stock' => $this->stock_qty,
        ];
    }
}
