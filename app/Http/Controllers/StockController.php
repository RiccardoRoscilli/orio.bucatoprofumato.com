<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function qty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',');
    }
}
