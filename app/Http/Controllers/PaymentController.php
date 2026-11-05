<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Vne\FakeVneClient;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function start(Request $request, Sale $sale, VneClient $vne)
    {
        $method = $request->validate(['method' => 'required|in:contanti,carta,pos'])['method'];

        abort_unless(in_array($sale->status, ['bozza'], true), 422, 'La vendita non è in bozza.');

        // POS esterno: l'operatore conferma di aver incassato col terminale
        if ($method === 'pos') {
            $sale->update(['payment_method' => 'pos', 'status' => 'pagata', 'paid_cents' => $sale->total_cents, 'paid_at' => now()]);

            return response()->json(['state' => 'completed', 'sale' => $sale]);
        }

        try {
            $id = $vne->pay($sale->total_cents, $method === 'carta', $sale->id);
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
    public function poll(Sale $sale, VneClient $vne)
    {
        if ($sale->status !== 'in_pagamento') {
            return response()->json(['state' => $sale->status === 'pagata' ? 'completed' : 'cancelled', 'sale' => $sale]);
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

    public function cancel(Request $request, Sale $sale, VneClient $vne)
    {
        $mode = (int) $request->validate(['mode' => 'required|in:1,2'])['mode'];

        abort_unless($sale->status === 'in_pagamento', 422, 'Nessun pagamento in corso.');

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
        abort_unless(config('orio.vne.driver') === 'fake', 404);

        FakeVneClient::setScenario((string) $sale->vne_transaction_id, $request->input('scenario'));

        return response()->json(['ok' => true]);
    }
}
