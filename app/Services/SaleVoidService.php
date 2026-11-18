<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
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
        // Anche una bozza con una parte gia' pagata (pagamento a parti interrotto)
        return in_array($sale->status, ['pagata', 'scontrinata'], true)
            || ($sale->status === 'bozza' && $sale->paid_cents > 0);
    }

    public function needsFiscalVoid(Sale $sale): bool
    {
        return $sale->status === 'scontrinata' && ! $sale->receipt_voided_at;
    }

    /** Parti pagate da rimborsare sulla carta (terminale collegato). */
    public function cardRefunds(Sale $sale)
    {
        $payments = $sale->payments()->where('status', 'pagato')->get();
        if ($payments->isEmpty() && $sale->payment_method === 'pos' && $sale->pos_order_id && $sale->pos_transaction_id && ! $sale->refunded_at) {
            // Vendita senza parti registrate: un solo pagamento, quello della vendita
            $payments = collect([new SalePayment([
                'method' => 'pos', 'paid_cents' => $sale->paid_cents ?: $sale->total_cents, 'status' => 'pagato',
                'terminal_id' => $sale->pos_terminal_id, 'pos_order_id' => $sale->pos_order_id, 'pos_transaction_id' => $sale->pos_transaction_id,
            ])]);
        }

        return $payments->filter(fn (SalePayment $p) => $p->refundableOnCard())->values();
    }

    public function needsRefund(Sale $sale): bool
    {
        return $this->cardRefunds($sale)->isNotEmpty();
    }

    /** @return array{state:string, message:string} */
    public function finish(Sale $sale, ?int $userId): array
    {
        if ($this->needsFiscalVoid($sale)) {
            throw new \LogicException('Lo scontrino non è ancora stato annullato sulla stampante.');
        }

        $refunded = 0;
        foreach ($this->cardRefunds($sale) as $payment) {
            try {
                $this->pos->forTerminal($payment->terminal_id)->refund($payment->pos_order_id, $payment->paid_cents, $sale->id);
            } catch (PosException $e) {
                return [
                    'state' => 'refund_failed',
                    'message' => ($sale->receipt_voided_at ? 'Scontrino annullato, ma il rimborso sulla carta non è riuscito: ' : 'Rimborso sulla carta non riuscito: ')
                        . $e->getMessage() . ' Riprova, oppure rimborsa dal POS o dalla dashboard MultiSafepay.',
                ];
            }
            if ($payment->exists) {
                $payment->update(['refunded_at' => now()]);
            }
            $refunded += $payment->paid_cents;
        }
        if ($refunded) {
            $sale->update(['refunded_at' => now()]);
        }

        $sale->update(['status' => 'annullata', 'voided_by' => $userId, 'closed_at' => $sale->closed_at ?? now()]);

        $byType = $sale->paidByType();
        $paid = $sale->payments()->where('status', 'pagato')->get();
        $manualCard = $paid->isEmpty()
            ? ($sale->refunded_at ? 0 : $byType['pos'])
            : (int) $paid->filter(fn (SalePayment $p) => $p->isCard() && ! $p->refunded_at)->sum('paid_cents');
        $messages = array_filter([
            $refunded ? euro($refunded) . ' rimborsati sulla carta del cliente.' : null,
            $manualCard > 0 ? 'Rimborsa ' . euro($manualCard) . ' al cliente dal POS.' : null,
            $byType['contanti'] ? 'Restituisci ' . euro($byType['contanti']) . ' in contanti al cliente.' : null,
        ]);

        return ['state' => 'done', 'message' => 'Vendita annullata. ' . implode(' ', $messages)];
    }
}
