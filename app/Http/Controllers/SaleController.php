<?php

namespace App\Http\Controllers;

use App\Services\Closure\ClosureStatus;
use App\Models\Product;
use App\Models\Attendance;
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
            'posTerminals' => config('orio.pos.driver') === 'manual' ? [] : \App\Services\Pos\CloudPos::terminalChoices(),
            'initial' => $this->openSaleState($request),
            'attendance' => ['present' => Attendance::presentCashiers(), 'required' => Attendance::required()],
            'discountPercent' => Sale::EMPLOYEE_DISCOUNT_PERCENT,
        ]);
    }

    /**
     * Vendita aperta dell'operatore da ripristinare in cassa dopo un ricaricamento della pagina:
     * la bozza (carrello), un pagamento ancora in corso sulla VNE o un pagamento incassato
     * per cui non e' stato emesso il documento.
     */
    private function openSaleState(Request $request): ?array
    {
        $sale = Sale::with('items.product.components', 'customer')
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['bozza', 'in_pagamento', 'pagata'])
            ->latest('updated_at')
            ->first();

        if (! $sale) {
            return null;
        }

        $pending = $sale->status === 'in_pagamento' ? $sale->pendingPayment() : null;

        return [
            'sale' => $sale->only(['id', 'status', 'total_cents', 'paid_cents', 'payment_method', 'pos_terminal_id', 'document_type']),
            'parts' => $sale->paymentParts(),
            'pending' => $pending ? ['method' => $pending->method, 'amount' => $pending->amount_cents, 'terminal' => $pending->terminal_id] : null,
            'document_type' => $sale->document_type,
            'customer' => $sale->customer,
            'employee_discount' => (bool) $sale->discount_percent,
            'rows' => $sale->items->map(fn ($item) => array_merge(
                $item->product?->toCartItem() ?? ['product_id' => $item->product_id, 'sku' => $item->sku, 'description' => $item->description, 'vat_rate' => $item->vat_rate],
                [
                    'description' => $item->description,
                    'qty' => (float) $item->qty,
                    // Bozza: sempre il prezzo di listino attuale. Vendita gia' in pagamento o
                    // pagata: il listino con cui e' stata incassata (lo sconto dipendenti lo
                    // riapplica la cassa, come per la bozza).
                    'price' => number_format(($sale->status === 'bozza' && $item->product ? $item->product->currentPriceCents() : ($item->list_price_cents ?? $item->unit_price_cents)) / 100, 2, ',', ''),
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
            'employee_discount' => 'nullable|boolean',
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

        // Con una parte gia' pagata il carrello e' bloccato: si paga il resto o si annulla
        if ($draft?->paid_cents) {
            return response()->json(['draft_id' => $draft->id, 'total_cents' => $draft->total_cents]);
        }

        $draft ??= Sale::create(['user_id' => $request->user()->id, 'document_type' => $data['document_type']]);
        $this->fillSale($draft, $data);

        return response()->json(['draft_id' => $draft->id, 'total_cents' => $draft->total_cents]);
    }

    public function index(Request $request)
    {
        // La cassa vede solo le vendite di oggi: il giorno lo sceglie solo l'admin
        $day = $request->user()->can('admin') ? ($request->date('day') ?? today()) : today();

        $sales = Sale::with('customer', 'user', 'cashier')
            ->whereDate('created_at', $day)
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->get();

        return view('sales.index', ['sales' => $sales, 'day' => $day, 'status' => $request->status]);
    }

    public function show(Sale $sale)
    {
        $sale->load('items', 'customer', 'user', 'cashier', 'vneTransactions', 'payments');

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
            'employee_discount' => 'nullable|boolean',
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

        $sale = $this->findDraft($request, $data['draft_id'] ?? null);

        // Resto di un pagamento a parti: le righe (e la cassiera) sono quelle gia' in parte pagate
        if ($sale?->paid_cents) {
            return response()->json(['sale' => $sale, 'parts' => $sale->paymentParts()]);
        }

        // Cassiera della vendita: l'unica presente, oppure quella scelta col badge se sono di piu'
        $cashierId = $this->cashierFor($request);
        if ($cashierId === false) {
            $present = Attendance::presentCashiers();

            return response()->json([
                'cashier_required' => true,
                'present' => $present,
                'message' => $present ? 'Chi sta facendo cassa? Bippa il tuo badge.' : 'Nessuna cassiera presente: bippa il badge per iniziare il turno.',
            ], 409);
        }

        $sale ??= Sale::create(['user_id' => $request->user()->id, 'document_type' => $data['document_type']]);
        $sale->cashier_id = $cashierId;
        $this->fillSale($sale, $data);

        if ($sale->total_cents <= 0) {
            return response()->json(['message' => 'Il totale deve essere maggiore di zero.'], 422);
        }

        return response()->json(['sale' => $sale]);
    }

    /**
     * Cassiera a cui intestare la vendita: null se i badge non sono in uso, false se va chiesta
     * (nessuna presente, oppure piu' di una e non e' stata indicata quale).
     */
    private function cashierFor(Request $request): int|false|null
    {
        if (! Attendance::required()) {
            return null;
        }

        $present = Attendance::open()->pluck('user_id');
        $chosen = $request->integer('cashier_id') ?: null;

        if ($chosen && $present->contains($chosen)) {
            return $chosen;
        }

        return $present->count() === 1 ? $present->first() : false;
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
     * dal browser. Il prezzo e' quello Orio gestito dagli admin in Prodotti, o quello promo
     * se oggi il prodotto e' in promo (le promo del sito non valgono in negozio).
     * Con lo sconto dipendenti (centro commerciale) ogni prezzo unitario cala della percentuale fissa.
     */
    private function fillSale(Sale $sale, array $data): void
    {
        $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');

        if ($excluded = $products->first(fn (Product $p) => ! $p->sold_in_shop)) {
            throw ValidationException::withMessages(['items' => "«" . trim($excluded->name) . "» non è in vendita in negozio: toglilo dal carrello."]);
        }

        if ($noPrice = $products->first(fn (Product $p) => ! $p->currentPriceCents())) {
            throw ValidationException::withMessages(['items' => "«{$noPrice->name}» non ha un prezzo di listino: va impostato in Prodotti."]);
        }

        $percent = ! empty($data['employee_discount']) ? Sale::EMPLOYEE_DISCOUNT_PERCENT : null;

        $sale->items()->delete();
        foreach ($data['items'] as $item) {
            $product = $products[$item['product_id']];
            $listCents = $product->currentPriceCents();
            $sale->items()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'description' => trim($product->name),
                'qty' => $item['qty'],
                'unit_price_cents' => $percent ? Sale::discountedCents($listCents, $percent) : $listCents,
                'list_price_cents' => $listCents,
                'vat_rate' => $product->vat_rate,
            ]);
        }

        $sale->document_type = $data['document_type'];
        $sale->discount_percent = $percent;
        $sale->customer_id = $data['document_type'] === 'fattura' ? ($data['customer_id'] ?? null) : null;
        $sale->load('items');
        $sale->recalculateTotal();
        $sale->save();
    }

    public function cancel(Sale $sale)
    {
        abort_if(in_array($sale->status, ['scontrinata', 'fatturata', 'in_pagamento'], true), 422, 'Vendita non annullabile in questo stato.');
        // Pagata col SmartPOS: l'annullo passa dal rimborso sulla carta (SaleVoidController)
        abort_if($sale->status === 'pagata' && $sale->pos_transaction_id, 422, 'Usa «Annulla e rimborsa»: il pagamento con carta va rimborsato.');
        // Bozza con una parte gia' incassata: si annulla con la restituzione (SaleVoidController)
        abort_if($sale->status === 'bozza' && $sale->paid_cents > 0, 422, 'Usa «Annulla e rimborsa»: una parte è già stata pagata.');

        $sale->update(['status' => 'annullata', 'closed_at' => now()]);

        return redirect()->route('sales.show', $sale)->with('status', 'Vendita annullata.');
    }
}
