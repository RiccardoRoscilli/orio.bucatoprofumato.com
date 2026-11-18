<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Services\Pos\CloudPos;
use App\Services\Pos\FakePos;
use App\Services\Pos\PosException;
use App\Services\Vne\FakeVneClient;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function start(Request $request, Sale $sale, VneClient $vne, CloudPos $pos)
    {
        $data = $request->validate([
            'method' => 'required|in:contanti,pos',
            'manual' => 'boolean',
            'terminal' => ['nullable', 'string', Rule::in(array_column(CloudPos::terminals(), 'id'))],
            // Pagamento a parti: importo di questa parte; senza, tutto il resto da pagare
            'amount' => 'nullable|integer|min:1',
        ]);
        $method = $data['method'];

        abort_unless(in_array($sale->status, ['bozza'], true), 422, 'La vendita non è in bozza.');

        $remaining = $sale->remainingCents();
        $amount = $data['amount'] ?? $remaining;
        abort_if($remaining <= 0, 422, 'La vendita è già pagata.');
        abort_if($amount > $remaining, 422, 'L\'importo supera il resto da pagare (' . euro($remaining) . ').');

        // Carta/bancomat con POS non collegato (o terminale cloud irraggiungibile): la cassa chiama
        // questo solo dopo che l'operatore ha confermato in Orio l'esito positivo sul POS
        if ($method === 'pos' && (config('orio.pos.driver') === 'manual' || ($data['manual'] ?? false))) {
            $sale->payments()->create(['method' => 'pos', 'amount_cents' => $amount, 'paid_cents' => $amount, 'status' => 'pagato', 'manual' => true, 'paid_at' => now()]);
            $sale->settlePayments();

            return $this->settled($sale);
        }

        // POS in cloud: l'importo va al terminale, l'esito arriva col polling
        if ($method === 'pos') {
            $pos = $pos->forTerminal($data['terminal'] ?? null);
            try {
                $orderId = $pos->start($sale, $amount);
            } catch (PosException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            $sale->payments()->create(['method' => 'pos', 'amount_cents' => $amount, 'terminal_id' => $pos->terminalId(), 'pos_order_id' => $orderId]);
            $sale->update(['payment_method' => $sale->paid_cents ? $sale->payment_method : 'pos', 'status' => 'in_pagamento', 'pos_terminal_id' => $pos->terminalId(), 'pos_order_id' => $orderId, 'vne_transaction_id' => null]);

            return response()->json(['state' => 'pending', 'order_id' => $orderId, 'terminal' => $pos->terminalId(), 'amount' => $amount]);
        }

        try {
            $id = $vne->pay($amount, false, $sale->id);
        } catch (VneException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $sale->payments()->create(['method' => $method, 'amount_cents' => $amount, 'vne_transaction_id' => $id]);
        $sale->update(['payment_method' => $sale->paid_cents ? $sale->payment_method : $method, 'status' => 'in_pagamento', 'vne_transaction_id' => $id, 'pos_order_id' => null]);

        return response()->json(['state' => 'pending', 'vne_id' => $id, 'amount' => $amount]);
    }

    /** Esito dopo una parte pagata: vendita pagata, oppure resto ancora da pagare. */
    private function settled(Sale $sale, array $extra = [])
    {
        $sale->refresh();

        return response()->json($extra + [
            'state' => $sale->status === 'pagata' ? 'completed' : 'part_completed',
            'sale' => $sale,
            'parts' => $sale->paymentParts(),
        ]);
    }

    /**
     * Polling dell'esito (tipo 2) chiamato dalla cassa ogni secondo: aggiorna la vendita
     * appena il pagamento si chiude e restituisce lo stato da mostrare all'operatore.
     */
    public function poll(Sale $sale, VneClient $vne, CloudPos $pos)
    {
        if ($sale->status !== 'in_pagamento') {
            return response()->json([
                'state' => $sale->status === 'pagata' ? 'completed' : ($sale->paid_cents ? 'part_completed' : 'cancelled'),
                'sale' => $sale,
                'parts' => $sale->paymentParts(),
            ]);
        }

        $payment = $this->current($sale);

        if ($payment->pos_order_id) {
            return $this->pollPos($sale, $payment, $pos);
        }

        try {
            $response = $vne->poll($payment->vne_transaction_id, $sale->id);
        } catch (VneException $e) {
            return response()->json(['state' => 'error', 'message' => $e->getMessage()]);
        }

        $details = $response['payment_details'] ?? [];
        $status = $details['status'] ?? 'pending';

        if ((int) $response['payment_status'] === 2) {
            return response()->json([
                'state' => $status === 'notCompleted' ? 'notCompleted' : 'pending',
                'details' => $details,
                'not_paid' => $status === 'notCompleted'
                    ? max(0, $details['inserted'] - ($details['amount'] + $details['rest']))
                    : 0,
            ]);
        }

        if ($status === 'completed') {
            $payment->update(['status' => 'pagato', 'paid_cents' => $payment->amount_cents, 'paid_at' => now()]);
            $sale->settlePayments();

            return $this->settled($sale, ['details' => $details]);
        }

        // returned / deleted / partial: la parte si chiude con quanto inserito (anche niente) e si
        // torna alla vendita; con un parziale resta da pagare il resto, in contanti o con carta
        $partial = $status === 'partial' ? max(0, (int) $details['inserted']) : 0;
        $payment->update($partial
            ? ['status' => 'pagato', 'paid_cents' => min($partial, $payment->amount_cents), 'paid_at' => now()]
            : ['status' => 'annullato']);
        $sale->update(['vne_transaction_id' => null]);
        $sale->settlePayments();
        $sale->refresh();

        return response()->json([
            'state' => $status === 'partial' ? 'partial' : 'cancelled',
            'details' => $details,
            'sale' => $sale,
            'parts' => $sale->paymentParts(),
        ]);
    }

    /** Esito del pagamento sul POS cloud: completato, rifiutato (si puo' riprovare) o annullato. */
    private function pollPos(Sale $sale, SalePayment $payment, CloudPos $pos)
    {
        try {
            $result = $pos->forTerminal($payment->terminal_id)->status($payment->pos_order_id, $sale->id);
        } catch (PosException $e) {
            return response()->json(['state' => 'error', 'message' => $e->getMessage()]);
        }

        if ($result['status'] === 'completed') {
            $payment->update(['status' => 'pagato', 'paid_cents' => $payment->amount_cents, 'paid_at' => now(), 'pos_transaction_id' => $result['transaction_id']]);
            $sale->update(['pos_transaction_id' => $result['transaction_id']]);
            $sale->settlePayments();

            return $this->settled($sale);
        }

        if (in_array($result['status'], CloudPos::FINAL_CANCELLED, true)) {
            $payment->update(['status' => 'annullato']);
            $sale->update(['pos_order_id' => null]);
            $sale->settlePayments();
            $sale->refresh();

            return response()->json(['state' => 'cancelled', 'pos_status' => $result['status'], 'sale' => $sale, 'parts' => $sale->paymentParts()]);
        }

        return response()->json(['state' => $result['status'] === 'declined' ? 'declined' : 'pending']);
    }

    /**
     * Parte in corso della vendita. Le vendite messe in pagamento prima dei pagamenti a parti
     * non hanno la riga: la si ricostruisce dai dati della vendita.
     */
    private function current(Sale $sale): SalePayment
    {
        return $sale->pendingPayment() ?? $sale->payments()->create([
            'method' => $sale->pos_order_id ? 'pos' : ($sale->payment_method ?: 'contanti'),
            'amount_cents' => $sale->remainingCents() ?: $sale->total_cents,
            'terminal_id' => $sale->pos_order_id ? $sale->pos_terminal_id : null,
            'pos_order_id' => $sale->pos_order_id,
            'vne_transaction_id' => $sale->pos_order_id ? null : $sale->vne_transaction_id,
        ]);
    }

    public function cancel(Request $request, Sale $sale, VneClient $vne, CloudPos $pos)
    {
        abort_unless($sale->status === 'in_pagamento', 422, 'Nessun pagamento in corso.');
        $payment = $this->current($sale);

        // POS cloud: l'annullo arriva al terminale, l'esito lo conferma il polling
        if ($payment->pos_order_id) {
            try {
                $status = $pos->forTerminal($payment->terminal_id)->cancel($payment->pos_order_id, $sale->id);
            } catch (PosException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            return response()->json(['message' => $status === 'completed'
                ? 'Impossibile annullare: il cliente ha già pagato.'
                : 'Annullamento inviato al POS…']);
        }

        $mode = (int) $request->validate(['mode' => 'required|in:1,2'])['mode'];

        try {
            $response = $vne->cancel($payment->vne_transaction_id, $mode, $sale->id);
        } catch (VneException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'payment_status' => $response['payment_status'] ?? null,
            'message' => match ((int) ($response['payment_status'] ?? 0)) {
                1 => 'Pagamento annullato.',
                2 => 'Annullamento in corso…',
                3 => 'Impossibile annullare: pagamento già concluso.',
                4 => 'Impossibile restituire il denaro: annulla accettando il parziale.',
                default => 'Risposta inattesa dalla cassa.',
            },
        ]);
    }

    /** Solo cassa simulata: sceglie come andrà a finire il pagamento in corso. */
    public function scenario(Request $request, Sale $sale)
    {
        if ($sale->pos_order_id) {
            abort_unless(config('orio.pos.driver') === 'fake', 404);
            FakePos::setScenario($sale->pos_order_id, (string) $request->input('scenario'));

            return response()->json(['ok' => true]);
        }

        abort_unless(config('orio.vne.driver') === 'fake', 404);

        FakeVneClient::setScenario((string) $sale->vne_transaction_id, $request->input('scenario'));

        return response()->json(['ok' => true]);
    }
}
