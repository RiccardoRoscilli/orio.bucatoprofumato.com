<?php

namespace App\Http\Controllers;

use App\Services\Closure\ClosureStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Services\FiscalPrinter\AxonG100;
use App\Services\FiscalPrinter\EpsonFpMateXml;
use App\Services\Vne\FakeVneClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function cassa(Request $request)
    {
        return view('cassa', [
            'vneDriver' => config('orio.vne.driver'),
            'scenarios' => FakeVneClient::SCENARIOS,
            'posDriver' => config('orio.pos.driver'),
            'posScenarios' => \App\Services\Pos\FakePos::SCENARIOS,
            'initial' => $this->openSaleState($request),
        ]);
    }

    /**
     * Vendita aperta dell'operatore da ripristinare in cassa dopo un ricaricamento della pagina:
     * la bozza (carrello), un pagamento ancora in corso sulla VNE o un pagamento incassato
     * per cui non e' stato emesso il documento.
     */
    private function openSaleState(Request $request): ?array
    {
        $sale = Sale::with('items.product', 'customer')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['bozza', 'in_pagamento', 'pagata'])
            ->latest('updated_at')
            ->first();

        if (! $sale) {
            return null;
        }

        return [
            'sale' => $sale->only(['id', 'status', 'total_cents', 'paid_cents', 'payment_method', 'document_type']),
            'document_type' => $sale->document_type,
            'customer' => $sale->customer,
            'rows' => $sale->items->map(fn ($item) => array_merge(
                $item->product?->toCartItem() ?? ['product_id' => $item->product_id, 'sku' => $item->sku, 'description' => $item->description, 'vat_rate' => $item->vat_rate],
                [
                    'description' => $item->description,
                    'qty' => (float) $item->qty,
                    // Bozza: sempre il prezzo di listino attuale. Vendita gia' in pagamento o
                    // pagata: il prezzo con cui e' stata incassata.
                    'price' => number_format(($sale->status === 'bozza' && $item->product ? $item->product->price_cents : $item->unit_price_cents) / 100, 2, ',', ''),
                    'vat_rate' => $item->vat_rate,
                ],
            ))->values(),
        ];
    }

    /**
     * Salvataggio automatico del carrello a ogni modifica: la vendita resta in "bozza" finche'
     * non si incassa e si emette il documento. Carrello vuoto = bozza eliminata.
     */
    public function saveDraft(Request $request)
    {
        $data = $request->validate([
            'draft_id' => 'nullable|integer',
            'document_type' => 'required|in:scontrino,fattura',
            'customer_id' => 'nullable|exists:customers,id',
            'items' => 'present|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.price' => 'nullable',
        ]);

        $draft = $this->findDraft($request, $data['draft_id'] ?? null);

        if (! $data['items']) {
            // Una bozza con un pagamento parziale gia' incassato non si cancella
            if ($draft && ! $draft->paid_cents) {
                $draft->delete();
            }

            return response()->json(['draft_id' => null]);
        }

        $draft ??= Sale::create(['user_id' => $request->user()->id, 'document_type' => $data['document_type']]);
        $this->fillSale($draft, $data);

        return response()->json(['draft_id' => $draft->id, 'total_cents' => $draft->total_cents]);
    }

    public function index(Request $request)
    {
        $day = $request->date('day') ?? today();

        $sales = Sale::with('customer', 'user')
            ->whereDate('created_at', $day)
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->get();

        return view('sales.index', ['sales' => $sales, 'day' => $day, 'status' => $request->status]);
    }

    public function show(Sale $sale)
    {
        $sale->load('items', 'customer', 'user', 'vneTransactions');

        return view('sales.show', [
            'sale' => $sale,
            'receiptXml' => $sale->receipt_xml ?? ($sale->items->isNotEmpty() ? $this->receiptPreviewCommands($sale) : null),
        ]);
    }

    /** Conferma della bozza per l'incasso: stessi dati del salvataggio, con i controlli completi. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'draft_id' => 'nullable|integer',
            'document_type' => 'required|in:scontrino,fattura',
            'customer_id' => 'nullable|required_if:document_type,fattura|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.price' => 'nullable',
        ], [
            'customer_id.required_if' => 'Per la fattura serve il cliente: cerca la partita IVA.',
            'items.required' => 'Aggiungi almeno una riga.',
            'items.*.product_id.required' => 'Si possono vendere solo prodotti del catalogo bucatoprofumato.com.',
        ]);

        if ($data['document_type'] === 'scontrino' && $block = app(ClosureStatus::class)->receiptBlockMessage()) {
            return response()->json(['message' => $block], 423);
        }

        $sale = $this->findDraft($request, $data['draft_id'] ?? null)
            ?? Sale::create(['user_id' => $request->user()->id, 'document_type' => $data['document_type']]);
        $this->fillSale($sale, $data);

        if ($sale->total_cents <= 0) {
            return response()->json(['message' => 'Il totale deve essere maggiore di zero.'], 422);
        }

        return response()->json(['sale' => $sale]);
    }

    /** La bozza indicata, solo se e' dell'operatore e ancora in bozza (non in pagamento o chiusa). */
    /** Anteprima dei comandi che verrebbero mandati alla stampante fiscale configurata. */
    private function receiptPreviewCommands(Sale $sale): string
    {
        return config('orio.fiscal_printer.driver') === 'epson'
            ? EpsonFpMateXml::receipt($sale)
            : implode("\n", AxonG100::receipt($sale));
    }

    private function findDraft(Request $request, ?int $id): ?Sale
    {
        if (! $id) {
            return null;
        }

        $sale = Sale::where('user_id', $request->user()->id)->find($id);
        abort_if($sale && $sale->status !== 'bozza', 409, 'Questa vendita non è più in bozza.');

        return $sale;
    }

    /**
     * Righe ricostruite dal catalogo: descrizione, SKU, IVA e prezzo vengono dal prodotto, non
     * dal browser. In negozio si vende sempre a prezzo pieno (le promozioni valgono solo per
     * l'online): il prezzo e' quello di listino gestito dagli admin in Prodotti.
     */
    private function fillSale(Sale $sale, array $data): void
    {
        $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

        if ($excluded = $products->first(fn (Product $p) => ! $p->sold_in_shop)) {
            throw ValidationException::withMessages(['items' => "«" . trim($excluded->name) . "» non è in vendita in negozio: toglilo dal carrello."]);
        }

        if ($noPrice = $products->first(fn (Product $p) => ! $p->price_cents)) {
            throw ValidationException::withMessages(['items' => "«{$noPrice->name}» non ha un prezzo di listino: va impostato in Prodotti."]);
        }

        $sale->items()->delete();
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $sale->items()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'description' => trim($product->name),
                'qty' => $item['qty'],
                'unit_price_cents' => $product->price_cents,
                'vat_rate' => $product->vat_rate,
            ]);
        }

        $sale->document_type = $data['document_type'];
        $sale->customer_id = $data['document_type'] === 'fattura' ? ($data['customer_id'] ?? null) : null;
        $sale->load('items');
        $sale->recalculateTotal();
        $sale->save();
    }

    public function cancel(Sale $sale)
    {
        abort_if(in_array($sale->status, ['scontrinata', 'fatturata', 'in_pagamento'], true), 422, 'Vendita non annullabile in questo stato.');

        $sale->update(['status' => 'annullata', 'closed_at' => now()]);

        return redirect()->route('sales.show', $sale)->with('status', 'Vendita annullata.');
    }
}
