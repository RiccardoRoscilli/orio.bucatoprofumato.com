<?php

namespace App\Http\Controllers;

use App\Models\CashClosure;
use App\Models\Sale;
use App\Services\Vne\VneClient;
use App\Services\Vne\VneException;
use Illuminate\Http\Request;

class ClosureController extends Controller
{
    public function index(VneClient $vne)
    {
        try {
            $status = $vne->status();
            $vneError = null;
        } catch (VneException $e) {
            $status = null;
            $vneError = $e->getMessage();
        }

        return view('closure', [
            'totals' => $this->todayTotals(),
            'status' => $status,
            'vneError' => $vneError,
            'closures' => CashClosure::with('user')->latest()->limit(10)->get(),
        ]);
    }

    public function store(Request $request, VneClient $vne)
    {
        try {
            $data = $vne->closure();
        } catch (VneException $e) {
            return back()->with('error', $e->getMessage());
        }

        $totals = $this->todayTotals();

        CashClosure::create([
            'user_id' => $request->user()->id,
            'day' => today(),
            'vne_data' => $data,
            'orio_cash_cents' => $totals['contanti'],
            'orio_card_cents' => $totals['carta'],
            'orio_pos_cents' => $totals['pos'],
        ]);

        return redirect()->route('closure')->with('status', 'Chiusura cassa registrata.');
    }

    /** @return array<string, int> incassato oggi per metodo di pagamento, in centesimi */
    private function todayTotals(): array
    {
        $sales = Sale::whereDate('paid_at', today())->whereIn('status', ['pagata', 'scontrinata', 'fatturata'])->get();

        return [
            'contanti' => (int) $sales->where('payment_method', 'contanti')->sum('paid_cents'),
            'carta' => (int) $sales->where('payment_method', 'carta')->sum('paid_cents'),
            'pos' => (int) $sales->where('payment_method', 'pos')->sum('paid_cents'),
            'scontrini' => $sales->where('status', 'scontrinata')->count(),
            'fatture' => $sales->where('status', 'fatturata')->count(),
            'da_documentare' => $sales->where('status', 'pagata')->count(),
        ];
    }
}
