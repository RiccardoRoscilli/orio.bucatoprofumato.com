<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\TeaOrder;
use App\Services\Stock\StockMinExcel;
use App\Services\Tea\TeaOrderClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Riassortimento del negozio: proposta dei prodotti sotto scorta, invio dell'ordine a TEA
 * (teaerp, listino d'acquisto, ritiro in sede) e storico con lo stato di lavorazione.
 */
class TeaOrderController extends Controller
{
    public function index()
    {
        $proposal = Product::sellable()->belowMinimum()->orderBy('name')->get()
            ->map(fn (Product $p) => $this->row($p, $p->suggestedReorderQty()));

        return view('tea-orders.index', [
            'proposal' => $proposal,
            'withMinimum' => Product::sellable()->whereNotNull('stock_min')->count(),
            'orders' => TeaOrder::withCount('items')->with('user:id,name')->latest('id')->limit(50)->get(),
            'configured' => (bool) (config('orio.tea.url') && config('orio.tea.user') && config('orio.tea.password')),
            'shipping' => config('orio.tea.shipping'),
        ]);
    }

    public function show(TeaOrder $teaOrder)
    {
        return view('tea-orders.show', ['order' => $teaOrder->load('items', 'user:id,name')]);
    }

    public function store(Request $request, TeaOrderClient $client)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|integer|min:1|max:100000',
            'notes' => 'nullable|string|max:500',
        ], ['items.required' => 'Aggiungi almeno un prodotto.']);

        $order = DB::transaction(function () use ($data, $request) {
            $order = TeaOrder::create(['status' => 'bozza', 'notes' => $data['notes'] ?? null, 'user_id' => $request->user()->id]);
            $order->update(['reference' => 'ORIO-' . $order->id]);

            $qty = [];
            foreach ($data['items'] as $item) {
                $qty[$item['product_id']] = ($qty[$item['product_id']] ?? 0) + (int) $item['qty'];
            }
            foreach (Product::whereIn('id', array_keys($qty))->get() as $product) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => trim($product->name),
                    'qty' => $qty[$product->id],
                    'stock_at_order' => $product->stock_qty,
                ]);
            }

            return $order;
        });

        return $this->deliver($order, $client);
    }

    /** Nuovo tentativo di un ordine non arrivato: stesso riferimento, teaerp non crea doppioni. */
    public function resend(TeaOrder $teaOrder, TeaOrderClient $client)
    {
        abort_if($teaOrder->status === 'inviato', 422, 'Ordine già inviato a TEA.');

        return $this->deliver($teaOrder, $client);
    }

    public function refresh(TeaOrder $teaOrder, TeaOrderClient $client)
    {
        abort_unless($teaOrder->status === 'inviato', 422, 'Ordine non ancora inviato.');

        $result = $client->status($teaOrder);
        if ($result['ok']) {
            $teaOrder->update(['tea_status' => $result['data']['order']['erp_status'] ?? null, 'status_checked_at' => now()]);
        }

        return response()->json(['ok' => $result['ok'], 'message' => $result['ok'] ? 'Stato aggiornato.' : $result['message'], 'tea_status' => $teaOrder->tea_status]);
    }

    public function minimumTemplate(StockMinExcel $excel)
    {
        $writer = new Xlsx($excel->template());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'Orio-scorte-minime-' . now()->format('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function minimumImport(Request $request, StockMinExcel $excel)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt,ods|max:10240'], ['file.mimes' => 'Carica il file Excel (.xlsx).']);
        $r = $excel->import($request->file('file'));

        $redirect = redirect()->route('tea-orders.index')->with('status', "Scorte minime aggiornate: {$r['updated']} prodotti cambiati, {$r['unchanged']} invariati.");
        if ($r['problems']) {
            $redirect->with('error', 'Righe non importate (' . count($r['problems']) . '): ' . implode('; ', array_slice($r['problems'], 0, 15)) . (count($r['problems']) > 15 ? '…' : ''));
        }

        return $redirect;
    }

    /** Scorta minima e riordino dalla scheda prodotto. */
    public function updateMinimum(Request $request, Product $product)
    {
        $data = $request->validate([
            'stock_min' => 'nullable|numeric|min:0|max:99999',
            'reorder_qty' => 'nullable|numeric|min:0|max:99999',
        ]);
        $product->update(['stock_min' => $data['stock_min'] ?? null, 'reorder_qty' => $data['reorder_qty'] ?? null]);

        return back()->with('status', 'Scorta minima salvata.');
    }

    private function deliver(TeaOrder $order, TeaOrderClient $client)
    {
        $result = $client->send($order);

        if (! $result['ok']) {
            $order->update(['status' => 'errore', 'error' => $result['message'], 'response' => $result['data'] ?: null]);

            return response()->json(['ok' => false, 'message' => 'Ordine non inviato a TEA: ' . $result['message'], 'show_url' => route('tea-orders.show', $order)], 502);
        }

        $tea = $result['data']['order'];
        $prices = collect($tea['items'] ?? [])->keyBy('sku');
        foreach ($order->items as $item) {
            if ($line = $prices->get($item->sku)) {
                $item->update(['price_cents' => (int) round($line['price'] * 100), 'row_total_cents' => (int) round($line['row_total'] * 100)]);
            }
        }
        $order->update([
            'status' => 'inviato',
            'error' => null,
            'tea_increment_id' => $tea['increment_id'] ?? null,
            'tea_status' => $tea['erp_status'] ?? null,
            'subtotal_cents' => (int) round(($tea['subtotal'] ?? 0) * 100),
            'tax_cents' => (int) round(($tea['tax_amount'] ?? 0) * 100),
            'total_cents' => (int) round(($tea['grand_total'] ?? 0) * 100),
            'response' => $result['data'],
            'sent_at' => now(),
            'status_checked_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => "Ordine {$order->tea_increment_id} inviato a TEA: " . euro($order->total_cents) . ' IVA inclusa.',
            'show_url' => route('tea-orders.show', $order),
        ]);
    }

    private function row(Product $p, int $qty): array
    {
        return [
            'product_id' => $p->id,
            'sku' => $p->sku,
            'name' => trim($p->name),
            'format' => $p->format,
            'stock' => $p->stock_qty,
            'stock_min' => $p->stock_min,
            'qty' => $qty,
        ];
    }
}
