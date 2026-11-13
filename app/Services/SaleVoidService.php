<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\Pos\CloudPos;
use App\Services\Pos\PosException;

/**
 * Annullo di una vendita gia' incassata, in tre passaggi che si possono riprendere:
 *  1. scontrino emesso -> documento commerciale di annullo sulla stampante fiscale;
 *  2. pagata col SmartPOS -> rimborso dell'intero importo sulla carta (MultiSafepay);
 *  3. vendita "annullata": esce dagli incassi e la merce rientra in magazzino.
 * Contanti e POS non collegato si restituiscono a mano: Orio lo ricorda all'operatore.
 */
class SaleVoidService
{
    public function __construct(private CloudPos $pos)
    {
    }

    public static function canVoid(Sale $sale): bool
    {
        return in_array($sale->status, ['pagata', 'scontrinata'], true);
    }

    public function needsFiscalVoid(Sale $sale): bool
    {
        return $sale->status === 'scontrinata' && ! $sale->receipt_voided_at;
    }

    public function needsRefund(Sale $sale): bool
    {
        return $sale->payment_method === 'pos' && $sale->pos_order_id && $sale->pos_transaction_id && ! $sale->refunded_at;
    }

    /** @return array{state:string, message:string} */
    public function finish(Sale $sale, ?int $userId): array
    {
        if ($this->needsFiscalVoid($sale)) {
            throw new \LogicException('Lo scontrino non è ancora stato annullato sulla stampante.');
        }

        if ($this->needsRefund($sale)) {
            try {
                $this->pos->refund($sale->pos_order_id, $sale->paid_cents ?: $sale->total_cents, $sale->id);
            } catch (PosException $e) {
                return [
                    'state' => 'refund_failed',
                    'message' => ($sale->receipt_voided_at ? 'Scontrino annullato, ma il rimborso sulla carta non è riuscito: ' : 'Rimborso sulla carta non riuscito: ')
                        . $e->getMessage() . ' Riprova, oppure rimborsa dal POS o dalla dashboard MultiSafepay.',
                ];
            }
            $sale->update(['refunded_at' => now()]);
        }

        $sale->update(['status' => 'annullata', 'voided_by' => $userId]);

        $amount = euro($sale->paid_cents ?: $sale->total_cents);

        return ['state' => 'done', 'message' => match (true) {
            (bool) $sale->refunded_at => "Vendita annullata e {$amount} rimborsati sulla carta del cliente.",
            $sale->payment_method === 'pos' => "Vendita annullata. Rimborsa {$amount} al cliente dal POS.",
            default => "Vendita annullata. Restituisci {$amount} in contanti al cliente.",
        }];
    }
}
