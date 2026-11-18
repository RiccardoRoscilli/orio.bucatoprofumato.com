<?php

namespace App\Http\Controllers;

use App\Models\CashClosure;
use App\Models\Sale;
use App\Services\Closure\ClosureStatus;
use App\Services\FiscalPrinter\AxonG100;
use App\Services\FiscalPrinter\EpsonFpMateXml;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Chiusura giornata in due passi, entrambi dal pulsante "Chiudi giornata":
 * 1. chiusura fiscale RT (report Z), obbligatoria entro le 24: con la Epson l'XML va al browser
 *    della postazione che lo manda alla stampante e poi conferma il numero di chiusura;
 * 2. chiusura della cassa automatica VNE, con il confronto degli incassi dello stesso periodo.
 */
class ClosureController extends Controller
{
    public function index(VneClient $vne, ClosureStatus $closureStatus)
    {
        // Cassa collegata dal tablet: lo stato lo carica la pagina (vneStatus), qui non si raggiunge
        $vneDeferred = config('orio.vne.driver') === 'browser';
        [$status, $vneError] = $vneDeferred ? [null, null] : $this->readVneStatus($vne);

        $from = $this->periodFrom();

        return view('closure', [
            'periodFrom' => $from,
            'totals' => $this->totals($from, now()),
            'status' => $status,
            'vneError' => $vneError,
            'vneDeferred' => $vneDeferred,
            'fiscalClosedToday' => $closureStatus->fiscalClosedToday(),
            'overdueDay' => $closureStatus->overdueDay(),
            'pendingVne' => CashClosure::whereNotNull('fiscal_closed_at')->whereNull('vne_closed_at')->latest()->first(),
            'closures' => CashClosure::with('user')->latest()->limit(10)->get(),
        ]);
    }

    /** Passo 1: chiusura fiscale. Simulata: registrata subito. Axon/Epson: comandi per il browser. */
    public function fiscal(Request $request)
    {
        if (config('orio.fiscal_printer.driver') === 'axon') {
            return response()->json([
                'driver' => 'axon',
                'base' => 'http://' . config('orio.fiscal_printer.host'),
                'commands' => AxonG100::zReport(),
            ]);
        }

        if (config('orio.fiscal_printer.driver') === 'epson') {
            return response()->json([
                'driver' => 'epson',
                'url' => 'https://' . config('orio.fiscal_printer.host') . '/cgi-bin/fpmate.cgi',
                'xml' => EpsonFpMateXml::zReport(),
            ]);
        }

        $number = sprintf('%04d', CashClosure::whereNotNull('fiscal_closed_at')->count() + 1);

        return response()->json(['driver' => 'fake', 'closure' => $this->createClosure($request, $number)]);
    }

    /** Conferma dal browser dopo che la stampante ha eseguito il report Z. */
    public function fiscalConfirm(Request $request)
    {
        $data = $request->validate(['z_number' => 'required|string|max:30']);

        return response()->json(['closure' => $this->createClosure($request, $data['z_number'])]);
    }

    /** Passo 2: chiusura della cassa automatica VNE; si puo' ripetere se la macchina non risponde. */
    /** Riquadro dello stato della cassa automatica, chiesto dal tablet (collegamento dal tablet). */
    public function vneStatus(VneClient $vne)
    {
        [$status, $vneError] = $this->readVneStatus($vne);

        return view('closure-vne-status', ['status' => $status, 'vneError' => $vneError]);
    }

    /** @return array{0:?array, 1:?string} stato e messaggio d'errore */
    private function readVneStatus(VneClient $vne): array
    {
        try {
            return [$vne->status(), null];
        } catch (VneException $e) {
            return [null, $e->getMessage()];
        }
    }

    public function vne(CashClosure $closure, VneClient $vne)
    {
        abort_if($closure->vne_closed_at, 422, 'La cassa automatica è già stata chiusa per questa giornata.');

        try {
            $data = $vne->closure();
        } catch (VneException $e) {
            $closure->update(['vne_error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage(), 'closure' => $closure], 502);
        }

        $closure->update(['vne_data' => $data, 'vne_closed_at' => now(), 'vne_error' => null]);

        return response()->json(['closure' => $closure]);
    }

    private function createClosure(Request $request, string $zNumber): CashClosure
    {
        $from = $this->periodFrom();
        $totals = $this->totals($from, now());

        return CashClosure::create([
            'user_id' => $request->user()->id,
            'day' => today(),
            'period_from' => $from,
            'fiscal_z_number' => $zNumber,
            'fiscal_closed_at' => now(),
            'orio_cash_cents' => $totals['contanti'],
            'orio_card_cents' => $totals['carta'],
            'orio_pos_cents' => $totals['pos'],
        ]);
    }

    /** Il periodo parte dalla chiusura precedente: se un giorno e' stato saltato, lo comprende. */
    private function periodFrom(): ?Carbon
    {
        return CashClosure::latest('id')->value('created_at');
    }

    /** @return array<string, int> incassi del periodo per metodo di pagamento, in centesimi */
    private function totals(?Carbon $from, Carbon $to): array
    {
        $sales = Sale::with('payments')->whereIn('status', ['pagata', 'scontrinata', 'fatturata'])
            ->when($from, fn ($q) => $q->where('paid_at', '>', $from))
            ->where('paid_at', '<=', $to)
            ->get();
        // Pagamenti a parti: ogni vendita conta per contanti e carta secondo le sue parti
        $byType = $sales->map(fn (Sale $sale) => $sale->paidByType());

        return [
            'contanti' => (int) $byType->sum('contanti'),
            'carta' => 0, // carta sulla VNE: non piu' usata, conteggiata con il POS
            'pos' => (int) $byType->sum('pos'),
            'scontrini' => $sales->where('status', 'scontrinata')->count(),
            'fatture' => $sales->where('status', 'fatturata')->count(),
            'da_documentare' => $sales->where('status', 'pagata')->count(),
        ];
    }
}
