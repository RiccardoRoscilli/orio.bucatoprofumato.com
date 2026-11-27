<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku', 'ean', 'ean2', 'name', 'format', 'category', 'categories', 'image_url', 'short_description', 'description',
        'site_price_cents', 'price_cents', 'promo_price_cents', 'promo_from', 'promo_until', 'vat_rate', 'active', 'sold_in_shop', 'shop_only', 'stock_min', 'reorder_qty', 'pcs_per_box', 'in_stock', 'synced_at',
    ];

    protected $casts = [
        'stock_min' => 'float',
        'reorder_qty' => 'float',
        'shop_only' => 'boolean',
        'site_price_cents' => 'integer',
        'price_cents' => 'integer',
        'promo_price_cents' => 'integer',
        'promo_from' => 'date',
        'promo_until' => 'date',
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

        return $query->where(fn ($q) => $q->whereIn('ean', $variants)->orWhereIn('ean2', $variants)->orWhereIn('sku', $variants));
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

    /** Promo del negozio in corso oggi (prezzo promo impostato e data di oggi dentro il periodo). */
    public function scopeInPromo(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query->whereNotNull('promo_price_cents')
            ->where(fn ($q) => $q->whereNull('promo_from')->orWhereDate('promo_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('promo_until')->orWhereDate('promo_until', '>=', $today));
    }

    public function promoActive(): bool
    {
        $today = today();

        return $this->promo_price_cents !== null
            && (! $this->promo_from || $this->promo_from->lte($today))
            && (! $this->promo_until || $this->promo_until->gte($today));
    }

    /** Prezzo a cui si vende oggi in cassa: quello promo se la promo e' in corso, altrimenti il prezzo Orio. */
    public function currentPriceCents(): ?int
    {
        return $this->promoActive() ? $this->promo_price_cents : $this->price_cents;
    }

    public function hasPriceToReview(): bool
    {
        return $this->site_price_cents !== null && $this->price_cents !== $this->site_price_cents;
    }

    /** Cesto composto da teaerp (SKU CESTO00001, vecchi CESTO-00001): pezzo unico. */
    public function isBasket(): bool
    {
        return str_starts_with(strtoupper($this->sku), 'CESTO');
    }

    /** Contenuto del cesto (vuoto per i prodotti normali). */
    public function components(): HasMany
    {
        return $this->hasMany(ProductComponent::class);
    }

    /**
     * Una parola di ricerca su nome/SKU/EAN del prodotto oppure, per i cesti, su nome/SKU/EAN
     * di uno dei prodotti contenuti: cercando "Relax" escono anche i cesti che lo contengono.
     */
    public function scopeMatchingTerm(Builder $query, string $term, array $extraColumns = []): Builder
    {
        $like = "%{$term}%";

        return $query->where(function ($q) use ($like, $extraColumns) {
            $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('ean', 'like', $like)->orWhere('ean2', 'like', $like);
            foreach ($extraColumns as $column) {
                $q->orWhere($column, 'like', $like);
            }
            $q->orWhereHas('components', fn ($c) => $c->where('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhereIn('sku', Product::query()->select('sku')->where('ean', 'like', $like)));
        });
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
            'price' => number_format($this->currentPriceCents() / 100, 2, ',', ''),
            'promo' => $this->promoActive(),
            'vat_rate' => $this->vat_rate,
            'stock' => $this->stock_qty,
            'stock_min' => $this->stock_min,
            // Contenuto del cesto (vuoto per i prodotti normali): popup dall'icona al posto della foto
            'components' => $this->components->map(fn ($c) => ['name' => $c->name, 'qty' => $c->qty])->all(),
        ];
    }

    /** Sotto scorta: c'e' una scorta minima e la giacenza e' scesa sotto. */
    public function scopeBelowMinimum($query)
    {
        return $query->whereNotNull('stock_min')->whereColumn('stock_qty', '<', 'stock_min');
    }

    /** Quantita' da ordinare a TEA: quella di riordino, altrimenti quanto manca alla scorta minima. */
    public function suggestedReorderQty(): int
    {
        if ($this->reorder_qty > 0) {
            return (int) ceil($this->reorder_qty);
        }

        return max(1, (int) ceil(($this->stock_min ?? 0) - $this->stock_qty));
    }
}
