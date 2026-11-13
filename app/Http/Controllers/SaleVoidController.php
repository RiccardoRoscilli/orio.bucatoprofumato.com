<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Closure\ClosureStatus;
use App\Services\FiscalPrinter\AxonG100;
use App\Services\SaleVoidService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Annullo di una vendita incassata dalla scheda vendita. Come per lo scontrino, con la Axon i
 * comandi tornano al browser della postazione, che li manda alla stampante e poi conferma il
 * numero del documento di annullo con confirm().
 */
class SaleVoidController extends Controller
{
    public function start(Request $request, Sale $sale, SaleVoidService $void)
    {
        abort_unless(SaleVoidService::canVoid($sale), 422, 'Questa vendita non si può annullare da qui.');

        if (! $void->needsFiscalVoid($sale)) {
            return $this->respond($void->finish($sale, $request->user()->id));
        }

        // Il documento di annullo e' un documento commerciale: valgono gli stessi blocchi dello scontrino
        if ($block = app(ClosureStatus::class)->receiptBlockMessage()) {
            return response()->json(['message' => $block], 423);
        }

        $driver = config('orio.fiscal_printer.driver');

        if ($driver === 'axon') {
            try {
                $commands = AxonG100::voidReceipt($sale);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'state' => 'printer',
                'driver' => 'axon',
                'base' => 'http://' . config('orio.fiscal_printer.host'),
                'commands' => $commands,
                'abort' => AxonG100::abortOpenDocument(),
            ]);
        }

        abort_if($driver === 'epson', 422, "Con la stampante Epson l'annullo va fatto dal menu della stampante.");

        // Stampante simulata: annullo registrato subito
        return $this->voided($sale, 'ANN-' . $sale->receipt_number, $request->user()->id, $void);
    }

    public function confirm(Request $request, Sale $sale, SaleVoidService $void)
    {
        $number = $request->validate(['void_number' => 'required|string|max:30'])['void_number'];
        abort_unless($sale->status === 'scontrinata', 422, 'Questa vendita non si può annullare da qui.');

        return $this->voided($sale, $number, $request->user()->id, $void);
    }

    private function voided(Sale $sale, string $number, int $userId, SaleVoidService $void)
    {
        DB::transaction(fn () => $sale->update(['receipt_voided_at' => now(), 'void_receipt_number' => $number, 'voided_by' => $userId]));

        return $this->respond($void->finish($sale->fresh(), $userId));
    }

    /** Il messaggio (es. "restituisci 5 € in contanti") resta visibile dopo il ricaricamento della pagina. */
    private function respond(array $result)
    {
        if ($result['state'] === 'done') {
            session()->flash('status', $result['message']);
        }

        return response()->json($result);
    }
}
