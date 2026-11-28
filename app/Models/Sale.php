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
        'misto' => 'Contanti e carta',
    ];

    /** Sconto dipendenti del centro commerciale, in percentuale sul listino. */
    public const EMPLOYEE_DISCOUNT_PERCENT = 10;

    protected $fillable = [
        'user_id', 'cashier_id', 'discount_percent', 'customer_id', 'status', 'document_type', 'payment_method',
        'total_cents', 'paid_cents', 'vne_transaction_id', 'pos_terminal_id', 'pos_order_id', 'pos_transaction_id', 'receipt_number', 'receipt_xml',
        'fattura24_doc_id', 'fattura24_doc_number', 'fattura24_xml', 'notes', 'paid_at', 'closed_at',
        'receipt_voided_at', 'void_receipt_number', 'refunded_at', 'voided_by',
    ];

    protected $casts = [
        'total_cents' => 'integer',
        'paid_cents' => 'integer',
        'discount_percent' => 'integer',
        'paid_at' => 'datetime',
        'closed_at' => 'datetime',
        'receipt_voided_at' => 'datetime',
        'refunded_at' => 'datetime',
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

    /** Cassiera che ha fatto la vendita (badge); senza badge e' l'utente collegato. */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** Prezzo unitario con lo sconto in percentuale, arrotondato al centesimo. */
    public static function discountedCents(int $cents, int $percent): int
    {
        return $cents - (int) round($cents * $percent / 100);
    }

    /** Quanto si e' risparmiato rispetto al listino (sconto dipendenti). */
    public function discountCents(): int
    {
        if (! $this->discount_percent) {
            return 0;
        }

        return (int) $this->items->sum(fn (SaleItem $item) => round($item->qty * ($item->list_price_cents ?? $item->unit_price_cents))) - $this->total_cents;
    }

    public function cashierName(): string
    {
        return $this->cashier?->name ?? $this->user?->name ?? '';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** Pagamento in corso (importo sul terminale o sulla VNE), se c'e'. */
    public function pendingPayment(): ?SalePayment
    {
        return $this->payments()->where('status', 'in_corso')->latest('id')->first();
    }

    public function remainingCents(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    /**
     * Ricalcola l'incassato dalle parti pagate: a totale raggiunto la vendita e' pagata,
     * altrimenti resta in bozza con l'incassato parziale e si paga il resto.
     */
    public function settlePayments(): void
    {
        $paid = $this->payments()->where('status', 'pagato')->get();
        $methods = $paid->map(fn (SalePayment $p) => $p->isCard() ? 'pos' : 'contanti')->unique();
        $paidCents = (int) $paid->sum('paid_cents');

        $this->update([
            'paid_cents' => $paidCents,
            'payment_method' => $methods->count() > 1 ? 'misto' : ($methods->first() ?? $this->payment_method),
            'status' => $paidCents >= $this->total_cents ? 'pagata' : 'bozza',
            'paid_at' => $paidCents >= $this->total_cents ? now() : null,
        ]);
    }

    /** @return array{contanti:int, pos:int} incassato per tipo (per scontrino e chiusura) */
    public function paidByType(): array
    {
        $paid = $this->payments->where('status', 'pagato');
        if ($paid->isEmpty() && $this->paid_cents) {
            // Vendita senza parti registrate (creata a mano o da dati vecchi)
            $card = in_array($this->payment_method, ['pos', 'carta'], true);

            return ['contanti' => $card ? 0 : $this->paid_cents, 'pos' => $card ? $this->paid_cents : 0];
        }

        return [
            'contanti' => (int) $paid->reject(fn (SalePayment $p) => $p->isCard())->sum('paid_cents'),
            'pos' => (int) $paid->filter(fn (SalePayment $p) => $p->isCard())->sum('paid_cents'),
        ];
    }

    /** Parti pagate per la cassa: metodo, importo, terminale. */
    public function paymentParts(): array
    {
        return $this->payments()->where('status', 'pagato')->orderBy('id')->get()
            ->map(fn (SalePayment $p) => ['id' => $p->id, 'label' => $p->label(), 'cents' => $p->paid_cents, 'card' => $p->isCard()])
            ->all();
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

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
