<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una parte dell'incasso di una vendita: contanti sulla VNE oppure carta su un terminale. */
class SalePayment extends Model
{
    protected $fillable = [
        'sale_id', 'method', 'amount_cents', 'paid_cents', 'status', 'manual', 'terminal_id',
        'pos_order_id', 'pos_transaction_id', 'vne_transaction_id', 'paid_at', 'refunded_at', 'refund_started_at', 'refunded_cents',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_cents' => 'integer',
        'manual' => 'boolean',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'refund_started_at' => 'datetime',
        'refunded_cents' => 'integer',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isCard(): bool
    {
        return in_array($this->method, ['pos', 'carta'], true);
    }

    /** Pagata col terminale collegato: si rimborsa da Orio sulla stessa carta. */
    public function refundableOnCard(): bool
    {
        return $this->isCard() && $this->pos_order_id && $this->pos_transaction_id && ! $this->refunded_at;
    }

    /** Contanti incassati dalla cassa automatica: si restituiscono con il rimborso VNE (tipo 65). */
    public function refundableOnVne(): bool
    {
        return ! $this->isCard() && ! $this->manual && $this->vne_transaction_id && $this->paid_cents > 0 && ! $this->refunded_at;
    }

    public function label(): string
    {
        if (! $this->isCard()) {
            return 'Contanti';
        }
        if ($this->manual) {
            return 'Carta (POS a mano)';
        }
        $name = collect(\App\Services\Pos\CloudPos::terminalChoices())->firstWhere('id', $this->terminal_id)['name'] ?? null;

        return 'Carta' . ($name ? " · {$name}" : ($this->terminal_id ? " · TID {$this->terminal_id}" : ''));
    }
}
