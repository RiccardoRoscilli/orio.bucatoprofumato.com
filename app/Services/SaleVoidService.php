<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Pos\CloudPos;
use App\Services\Pos\PosException;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;

/**
 * Annullo di una vendita gia' incassata, in tre passaggi che si possono riprendere:
 *  1. scontrino emesso -> documento commerciale di annullo sulla stampante fiscale;
 *  2. pagata col SmartPOS -> rimborso dell'intero importo sulla carta (MultiSafepay);
 *  3. vendita "annullata": esce dagli incassi e la merce rientra in magazzino;
 *  4. contanti incassati dalla cassa automatica -> la VNE li restituisce (rimborso tipo 65),
 *     con il browser che segue l'erogazione chiamando refundCash() finche' non finisce.
 * POS non collegato e contanti presi a mano si restituiscono a mano: Orio lo ricorda all'operatore.
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

    /** Parti in contanti che la cassa automatica puo' restituire. */
    public function cashRefunds(Sale $sale)
    {
        return $sale->payments()->where('status', 'pagato')->get()
            ->filter(fn (SalePayment $p) => $p->refundableOnVne())->values();
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

        $cashPending = $this->cashRefunds($sale)->isNotEmpty();

        return ['state' => 'done', 'message' => 'Vendita annullata.' . $this->summary($sale), 'cash_refund' => $cashPending];
    }

    /**
     * Un passo del rimborso dei contanti dalla cassa automatica, chiamato dal browser ogni
     * secondo: avvia il rimborso (tipo 65) della prima parte da restituire, poi ne segue
     * l'erogazione (tipo 66). Se la VNE rifiuta o non ha abbastanza denaro, la parte resta
     * chiusa con quanto erogato e il resto si restituisce a mano: niente nuovi tentativi
     * automatici, per non rischiare di dare i soldi due volte.
     *
     * @return array{state:string, message:string}
     */
    public function refundCash(Sale $sale, VneClient $vne): array
    {
        $payment = $this->cashRefunds($sale)->first();
        if (! $payment) {
            return ['state' => 'done', 'message' => 'Vendita annullata.' . $this->summary($sale)];
        }
        $check = ' Controlla sulla cassa automatica se ha già restituito i soldi prima di darli a mano; poi riprova dalla scheda della vendita.';

        try {
            if (! $payment->refund_started_at) {
                try {
                    $vne->refund($payment->vne_transaction_id, $sale->id);
                } finally {
                    // Anche senza risposta il rimborso potrebbe essere partito: da qui in poi solo il controllo (66)
                    $payment->update(['refund_started_at' => now()]);
                }

                return ['state' => 'refunding', 'message' => 'La cassa automatica sta restituendo ' . euro($payment->paid_cents) . '…'];
            }

            $response = $vne->refundPoll($payment->vne_transaction_id, $sale->id);
        } catch (VneException $e) {
            if (! $e->getCode()) {
                return ['state' => 'unreachable', 'message' => $e->getMessage() . '.' . $check];
            }
            // Rifiutato dalla cassa (es. rimborsi non abilitati): quella parte va restituita a mano
            $payment->update(['refunded_at' => now(), 'refunded_cents' => 0]);

            return $this->withNote($this->refundCash($sale, $vne), 'La cassa automatica non ha restituito i contanti: ' . $e->getMessage() . '.');
        }

        $refunded = (int) ($response['refunded'] ?? 0);
        $toRefund = (int) ($response['toRefund'] ?? $payment->paid_cents);
        if (($response['refund_status'] ?? null) !== 'completed') {
            return ['state' => 'refunding', 'message' => 'La cassa automatica sta restituendo ' . euro($toRefund) . '… (' . euro($refunded) . ' erogati)'];
        }

        $payment->update(['refunded_at' => now(), 'refunded_cents' => $refunded]);

        return $this->refundCash($sale, $vne);
    }

    private function withNote(array $result, string $note): array
    {
        if ($result['state'] === 'done') {
            $result['message'] .= ' ' . $note;
        }

        return $result;
    }

    /** Cosa e' stato rimborsato e cosa va restituito a mano, per il messaggio finale. */
    public function summary(Sale $sale): string
    {
        $paid = $sale->payments()->where('status', 'pagato')->get();
        $byType = $sale->paidByType();

        if ($paid->isEmpty()) {
            // Vendita senza parti registrate (dati vecchi)
            $onCard = $sale->refunded_at ? $byType['pos'] : 0;
            $manualCard = $byType['pos'] - $onCard;
            $fromVne = 0;
            $byHand = $byType['contanti'];
        } else {
            $cards = $paid->filter(fn (SalePayment $p) => $p->isCard());
            $cash = $paid->reject(fn (SalePayment $p) => $p->isCard());
            $onCard = (int) $cards->whereNotNull('refunded_at')->sum('paid_cents');
            $manualCard = (int) $cards->whereNull('refunded_at')->sum('paid_cents');
            $fromVne = (int) $cash->sum('refunded_cents');
            // Le parti che la cassa sta ancora restituendo non vanno date a mano
            $byHand = (int) $cash->sum('paid_cents') - $fromVne - (int) $cash->filter(fn (SalePayment $p) => $p->refundableOnVne())->sum('paid_cents');
        }

        return implode('', array_filter([
            $onCard ? ' ' . euro($onCard) . ' rimborsati sulla carta del cliente.' : null,
            $manualCard > 0 ? ' Rimborsa ' . euro($manualCard) . ' al cliente dal POS.' : null,
            $fromVne ? ' ' . euro($fromVne) . ' restituiti dalla cassa automatica.' : null,
            $byHand > 0 ? ' Restituisci ' . euro($byHand) . ' in contanti al cliente.' : null,
        ]));
    }
}
