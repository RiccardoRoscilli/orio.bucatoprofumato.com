<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockExcel;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Magazzino del negozio: carico della merce arrivata (tutti gli operatori), inventario con
 * rettifica delle giacenze (solo admin) e registro dei movimenti.
 */
class StockController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('tipo');
        $day = $request->date('giorno');

        $movements = StockMovement::with('product:id,name,sku', 'user:id,name', 'sale:id')
            ->when(array_key_exists((string) $type, StockMovement::TYPES), fn ($q) => $q->where('type', $type))
            ->when($day, fn ($q) => $q->whereDate('created_at', $day))
            ->latest('id')
            ->limit(300)
            ->get();

        return view('stock.index', [
            'movements' => $movements,
            'type' => $type,
            'day' => $day,
            'belowZero' => Product::where('stock_qty', '<', 0)->orderBy('name')->get(['id', 'name', 'sku', 'stock_qty']),
            'mode' => $request->routeIs('stock.inventory') ? 'inventario' : 'carico',
        ]);
    }

    /** Carico della merce arrivata: piu' righe in una volta, una nota comune (es. numero DDT). */
    public function load(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001|max:99999',
            'note' => 'nullable|string|max:255',
        ], ['items.required' => 'Aggiungi almeno un prodotto.']);

        $movements = DB::transaction(fn () => collect($data['items'])->map(fn ($item) => $stock->load(
            Product::findOrFail($item['product_id']),
            (float) $item['qty'],
            $request->user()->id,
            $data['note'] ?? null,
        )));

        return response()->json([
            'message' => 'Carico registrato: ' . $movements->count() . ' prodotti, ' . $this->qty($movements->sum('qty')) . ' pezzi.',
        ]);
    }

    /** Inventario: quantita' contata per un prodotto, la differenza diventa una rettifica. */
    public function adjust(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'counted' => 'required|numeric|min:0|max:99999',
            'note' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($data['product_id']);
        $movement = $stock->adjust($product, (float) $data['counted'], $request->user()->id, $data['note'] ?? null);
        $product->refresh();

        return response()->json([
            'stock' => $product->stock_qty,
            'message' => $movement
                ? "Giacenza di «{$product->name}» portata a {$product->stockLabel()} (" . ($movement->qty > 0 ? '+' : '') . $this->qty($movement->qty) . ').'
                : "Giacenza di «{$product->name}» confermata: {$product->stockLabel()}.",
        ]);
    }

    /** Modello Excel con i prodotti in vendita in negozio e la giacenza attuale. */
    public function excelTemplate(StockExcel $excel)
    {
        $writer = new Xlsx($excel->template());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'Orio-magazzino-' . now()->format('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Giacenze da Excel: azzeramento completo. Ogni prodotto del file prende la quantita' scritta
     * (cella vuota = 0), la differenza resta come rettifica nei movimenti.
     */
    public function excelImport(Request $request, StockExcel $excel)
    {
        $data = $request->validate([
            'mode' => 'nullable|in:carico,inventario', // solo per tornare alla pagina di partenza
            'file' => 'required|file|mimes:xlsx,xls,csv,txt,ods|max:10240',
            'note' => 'nullable|string|max:255',
        ], ['file.mimes' => 'Carica il file Excel (.xlsx).']);

        // Copia del file caricato, per poter ricontrollare cosa e' stato importato
        $file = $request->file('file');
        $file->storeAs('magazzino', now()->format('Y-m-d_His') . '_giacenze_'
            . Str::slug($request->user()->name) . '.' . strtolower($file->getClientOriginalExtension()), 'local');

        $note = trim('Giacenze da Excel ' . ($data['note'] ?? ''));
        $r = $excel->import($file, $request->user()->id, $note);

        $message = "Giacenze aggiornate da Excel: {$r['rows']} prodotti nel file, {$r['changed']} giacenze cambiate, {$r['unchanged']} già giuste.";

        $redirect = redirect()->route(($data['mode'] ?? null) === 'inventario' ? 'stock.inventory' : 'stock.index')->with('status', $message);
        if ($r['problems']) {
            $redirect->with('error', 'Righe non importate (' . count($r['problems']) . '): ' . implode('; ', array_slice($r['problems'], 0, 15))
                . (count($r['problems']) > 15 ? '…' : ''));
        }

        return $redirect;
    }

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',');
    }
}
