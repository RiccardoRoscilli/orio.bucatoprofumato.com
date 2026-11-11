<?php

namespace App\Models;

use App\Services\Stock\StockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    public const STATUSES = [
        'bozza' => 'Bozza',
        'in_pagamento' => 'In pagamento',
        'pagata' => 'Pagata',
        'scontrinata' => 'Scontrinata',
        'fatturata' => 'Fatturata',
        'annullata' => 'Annullata',
    ];

    public const PAYMENT_METHODS = [
        'contanti' => 'Contanti (VNE)',
        'carta' => 'Carta (VNE)', // non piu' usato: la VNE prende solo contanti
        'pos' => 'Carta / Bancomat (POS)',
    ];

    protected $fillable = [
        'user_id', 'customer_id', 'status', 'document_type', 'payment_method',
        'total_cents', 'paid_cents', 'vne_transaction_id', 'receipt_number', 'receipt_xml',
        'fattura24_doc_id', 'fattura24_doc_number', 'fattura24_xml', 'notes', 'paid_at', 'closed_at',
    ];

    protected $casts = [
        'total_cents' => 'integer',
        'paid_cents' => 'integer',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
        'stock_unloaded_at' => 'datetime',
    ];

    /**
     * Giacenze: la merce esce dal negozio quando la vendita e' pagata (anche se il documento
     * viene emesso dopo) e rientra se una vendita gia' scaricata viene annullata.
     */
    protected static function booted(): void
    {
        static::updated(function (Sale $sale) {
            if (! $sale->wasChanged('status')) {
                return;
            }

            if (in_array($sale->status, ['pagata', 'scontrinata', 'fatturata'], true) && ! $sale->stock_unloaded_at) {
                app(StockService::class)->unloadSale($sale);
            } elseif ($sale->status === 'annullata' && $sale->stock_unloaded_at) {
                app(StockService::class)->restoreSale($sale, auth()->id());
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vneTransactions(): HasMany
    {
        return $this->hasMany(VneTransaction::class);
    }

    public function recalculateTotal(): void
    {
        $this->total_cents = $this->items->sum(fn (SaleItem $item) => $item->lineTotalCents());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['scontrinata', 'fatturata', 'annullata'], true);
    }
}
