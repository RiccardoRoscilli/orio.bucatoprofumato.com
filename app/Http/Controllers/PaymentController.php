<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Pos\CloudPos;
use App\Services\Pos\FakePos;
use App\Services\Pos\PosException;
use App\Services\Vne\FakeVneClient;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function start(Request $request, Sale $sale, VneClient $vne, CloudPos $pos)
    {
        $data = $request->validate(['method' => 'required|in:contanti,pos', 'manual' => 'boolean']);
        $method = $data['method'];

        abort_unless(in_array($sale->status, ['bozza'], true), 422, 'La vendita non è in bozza.');

        // Carta/bancomat con POS non collegato (o terminale cloud irraggiungibile): la cassa chiama
        // questo solo dopo che l'operatore ha confermato in Orio l'esito positivo sul POS
        if ($method === 'pos' && (config('orio.pos.driver') === 'manual' || ($data['manual'] ?? false))) {
            $sale->update(['payment_method' => 'pos', 'status' => 'pagata', 'paid_cents' => $sale->total_cents, 'paid_at' => now()]);

            return response()->json(['state' => 'completed', 'sale' => $sale]);
        }

        // POS in cloud: l'importo va al terminale, l'esito arriva col polling
        if ($method === 'pos') {
            try {
                $orderId = $pos->start($sale);
            } catch (PosException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            $sale->update(['payment_method' => 'pos', 'status' => 'in_pagamento', 'pos_order_id' => $orderId]);

            return response()->json(['state' => 'pending', 'order_id' => $orderId]);
        }

        try {
            $id = $vne->pay($sale->total_cents, false, $sale->id);
        } catch (VneException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $sale->update(['payment_method' => $method, 'status' => 'in_pagamento', 'vne_transaction_id' => $id]);

        return response()->json(['state' => 'pending', 'vne_id' => $id]);
    }

    /**
     * Polling dell'esito (tipo 2) chiamato dalla cassa ogni secondo: aggiorna la vendita
     * appena il pagamento si chiude e restituisce lo stato da mostrare all'operatore.
     */
    public function poll(Sale $sale, VneClient $vne, CloudPos $pos)
    {
        if ($sale->status !== 'in_pagamento') {
            return response()->json(['state' => $sale->status === 'pagata' ? 'completed' : 'cancelled', 'sale' => $sale]);
        }

        if ($sale->pos_order_id) {
            return $this->pollPos($sale, $pos);
        }

        try {
            $response = $vne->poll($sale->vne_transaction_id, $sale->id);
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
            $sale->update(['status' => 'pagata', 'paid_cents' => $sale->total_cents, 'paid_at' => now()]);

            return response()->json(['state' => 'completed', 'details' => $details, 'sale' => $sale]);
        }

        // returned / deleted / partial: la vendita torna in bozza, l'operatore decide come proseguire
        $partial = $status === 'partial' ? max(0, (int) $details['inserted']) : 0;
        $sale->update([
            'status' => 'bozza',
            'paid_cents' => $partial,
            'vne_transaction_id' => null,
            'notes' => $partial ? 'Pagamento parziale accettato in cassa: € ' . number_format($partial / 100, 2, ',', '.') : $sale->notes,
        ]);

        return response()->json(['state' => $status === 'partial' ? 'partial' : 'cancelled', 'details' => $details, 'sale' => $sale]);
    }

    /** Esito del pagamento sul POS cloud: completato, rifiutato (si puo' riprovare) o annullato. */
    private function pollPos(Sale $sale, CloudPos $pos)
    {
        try {
            $result = $pos->status($sale->pos_order_id, $sale->id);
        } catch (PosException $e) {
            return response()->json(['state' => 'error', 'message' => $e->getMessage()]);
        }

        if ($result['status'] === 'completed') {
            $sale->update(['status' => 'pagata', 'paid_cents' => $sale->total_cents, 'paid_at' => now(), 'pos_transaction_id' => $result['transaction_id']]);

            return response()->json(['state' => 'completed', 'sale' => $sale]);
        }

        if (in_array($result['status'], CloudPos::FINAL_CANCELLED, true)) {
            $sale->update(['status' => 'bozza', 'pos_order_id' => null]);

            return response()->json(['state' => 'cancelled', 'pos_status' => $result['status'], 'sale' => $sale]);
        }

        return response()->json(['state' => $result['status'] === 'declined' ? 'declined' : 'pending']);
    }

    public function cancel(Request $request, Sale $sale, VneClient $vne, CloudPos $pos)
    {
        abort_unless($sale->status === 'in_pagamento', 422, 'Nessun pagamento in corso.');

        // POS cloud: l'annullo arriva al terminale, l'esito lo conferma il polling
        if ($sale->pos_order_id) {
            try {
                $status = $pos->cancel($sale->pos_order_id, $sale->id);
            } catch (PosException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }

            return response()->json(['message' => $status === 'completed'
                ? 'Impossibile annullare: il cliente ha già pagato.'
                : 'Annullamento inviato al POS…']);
        }

        $mode = (int) $request->validate(['mode' => 'required|in:1,2'])['mode'];

        try {
            $response = $vne->cancel($sale->vne_transaction_id, $mode, $sale->id);
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
