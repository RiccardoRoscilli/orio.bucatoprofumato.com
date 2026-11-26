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
    public function index(TeaOrderClient $client)
    {
        // TEA accetta solo scatole intere: pezzi per scatola da teaerp (1 se il prodotto non ha scatola)
        try {
            $boxes = $client->productBoxes();
            $teaBoxes = $client->teaBoxes();
            $boxesError = null;
        } catch (\RuntimeException $e) {
            $boxes = $teaBoxes = [];
            $boxesError = $e->getMessage();
        }
        $this->teaSkus = array_keys($teaBoxes);

        $perBox = fn (Product $p) => $boxes[trim((string) $p->sku)] ?? 1;
        $below = Product::sellable()->belowMinimum()->orderBy('name')->get();

        // L'ordine in preparazione resta aperto finche' non viene inviato: si riprende da dove era rimasto
        $open = TeaOrder::open()?->load('items.product', 'editor:id,name');
        if ($open) {
            $rows = $open->items->filter(fn ($i) => $i->product)
                ->map(fn ($i) => $this->row($i->product, $perBox($i->product), (int) $i->boxes))->values();
            $inOrder = $open->items->pluck('product_id')->all();
            // Sotto scorta ma non ancora nell'ordine: proposti a parte, si aggiungono con un clic
            $suggestions = $below->reject(fn (Product $p) => in_array($p->id, $inOrder, true))
                ->map(fn (Product $p) => $p->toCartItem())->values();
        } else {
            $rows = $below->map(fn (Product $p) => $this->row($p, $perBox($p), (int) ceil($p->suggestedReorderQty() / $perBox($p))))->values();
            $suggestions = collect();
        }

        return view('tea-orders.index', [
            'proposal' => $rows,
            'open' => $open,
            'suggestions' => $suggestions,
            'boxes' => $boxes,
            'teaSkus' => array_keys($teaBoxes),
            'boxesError' => $boxesError,
            'withMinimum' => Product::sellable()->whereNotNull('stock_min')->count(),
            'configured' => (bool) (config('orio.tea.url') && config('orio.tea.user') && config('orio.tea.password')),
            'shipping' => config('orio.tea.shipping'),
        ]);
    }

    /** Ordini inviati (o rifiutati) a TEA, con lo stato dell'arrivo della merce. */
    public function sent()
    {
        return view('tea-orders.sent', [
            'orders' => TeaOrder::where('status', '!=', 'aperto')->withCount('items')->with('user:id,name', 'items', 'ddts.items')->latest('id')->paginate(50),
        ]);
    }

    public function show(TeaOrder $teaOrder, TeaOrderClient $client)
    {
        if ($teaOrder->status === 'aperto') {
            return redirect()->route('tea-orders.index');
        }

        // Nessun DDT ancora: si chiede a teaerp se la merce e' partita (poi con "Aggiorna da teaerp")
        if ($teaOrder->status === 'inviato' && ! $teaOrder->ddts()->exists()) {
            $this->syncFromTea($teaOrder, $client);
        }

        return view('tea-orders.show', ['order' => $teaOrder->load('items', 'user:id,name', 'ddts.items.receiver:id,name', 'ddts.items.product:id,ean', 'ddts.confirmer:id,name')]);
    }

    /**
     * Chiede a teaerp i DDT degli ordini la cui merce non e' ancora tutta arrivata (gli ultimi 15),
     * cosi' lo storico dice se un ordine e' partito senza aprirlo uno per uno.
     */
    public function arrivals(TeaOrderClient $client)
    {
        $orders = TeaOrder::where('status', 'inviato')->with('items', 'ddts.items')->latest('id')->limit(50)->get()
            ->filter(fn (TeaOrder $o) => $o->arrival()['key'] === 'attesa' || $o->arrival()['missing'])->take(15);

        foreach ($orders as $order) {
            if ($this->syncFromTea($order, $client) === null) {
                break; // teaerp non risponde: inutile insistere
            }
        }

        return response()->json(TeaOrder::whereKey($orders->modelKeys())->with('items', 'ddts.items')->get()
            ->mapWithKeys(fn (TeaOrder $o) => [$o->id => $o->arrival()]));
    }

    /** Stato e DDT da teaerp. Ritorna i DDT nuovi, o null se teaerp non ha risposto. */
    private function syncFromTea(TeaOrder $order, TeaOrderClient $client): ?int
    {
        $result = $client->status($order);
        if (! $result['ok']) {
            return null;
        }
        $order->update(['tea_status' => $result['data']['order']['erp_status'] ?? null, 'status_checked_at' => now()]);

        try {
            $perBox = $client->productBoxes();
        } catch (\RuntimeException) {
            $perBox = [];
        }

        return $order->syncDdts($result['data']['order']['ddts'] ?? [], $perBox);
    }

    /**
     * Spunta di una riga del DDT a scatole intere: si salvano le scatole ricevute (anche diverse da
     * quelle spedite, 0 se non sono arrivate) e si possono correggere finche' il carico non e'
     * confermato. In magazzino non entra niente fino alla conferma.
     */
    public function receive(Request $request, \App\Models\TeaDdtItem $item)
    {
        $boxes = (int) $request->validate(['boxes' => 'required|integer|min:0|max:9999'], [
            'boxes.integer' => 'Scrivi le scatole intere ricevute.',
        ])['boxes'];
        abort_unless($item->product_id, 422, "«{$item->name}» ({$item->sku}) non è un prodotto di Orio: non si può caricare.");
        abort_if($item->ddt->confirmed_at, 422, 'Carico già confermato: non si può più modificare.');

        $qty = $boxes * max(1, $item->pcs_per_box);
        $item->update(['qty_received' => $qty, 'received_at' => now(), 'received_by' => $request->user()->id]);

        return response()->json(['item' => [
            'id' => $item->id, 'qty_received' => $qty, 'boxes_received' => $item->boxes($qty),
            'received_at' => $item->received_at->format('d/m H:i'), 'receiver' => $request->user()->name,
        ]]);
    }

    /**
     * Conferma del carico: con tutte le righe spuntate, le quantita' ricevute entrano in magazzino
     * in un colpo solo. Da qui in poi il DDT non si modifica piu'.
     */
    public function confirmDdt(Request $request, \App\Models\TeaDdt $ddt, \App\Services\Stock\StockService $stock)
    {
        $userId = $request->user()->id;

        $count = DB::transaction(function () use ($ddt, $userId, $stock) {
            $ddt = \App\Models\TeaDdt::whereKey($ddt->id)->lockForUpdate()->with('order', 'items.product')->first();
            abort_if($ddt->confirmed_at, 422, 'Carico già confermato.');
            $open = $ddt->items->whereNull('qty_received')->whereNotNull('product_id')->count();
            abort_if($open, 422, $open === 1 ? 'Manca ancora 1 riga da spuntare.' : "Mancano ancora {$open} righe da spuntare.");

            $loaded = 0;
            foreach ($ddt->items as $item) {
                if ($item->product && $item->qty_received > 0) {
                    $stock->load($item->product, $item->qty_received, $userId, "Arrivo DDT {$ddt->ddt_number} (ordine {$ddt->order->reference})");
                    $loaded++;
                }
            }
            $ddt->update(['confirmed_at' => now(), 'confirmed_by' => $userId]);

            return $loaded;
        });

        return response()->json(['message' => $count === 1 ? 'Carico confermato: 1 prodotto caricato in magazzino.' : "Carico confermato: {$count} prodotti caricati in magazzino."]);
    }

    /**
     * Salvataggio dell'ordine in preparazione a ogni modifica (scatole, righe, nota): resta aperto,
     * condiviso da tutto il negozio, finche' qualcuno non lo invia. Senza righe ne' nota si chiude.
     */
    public function saveOpen(Request $request, TeaOrderClient $client)
    {
        $data = $request->validate([
            'items' => 'present|array|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.boxes' => 'required|integer|min:0|max:9999',
            'items.*.pcs_per_box' => 'nullable|integer|min:1|max:9999',
            'notes' => 'nullable|string|max:500',
        ]);
        $userId = $request->user()->id;
        $this->rememberPerBox($data['items'], $client);
        // Pezzi indicativi finche' e' aperto: all'invio si ricalcolano con teaerp
        try {
            $perBox = $client->productBoxes();
        } catch (\RuntimeException) {
            $perBox = [];
        }

        $order = DB::transaction(function () use ($data, $userId, $perBox) {
            $order = TeaOrder::open();
            if (! $data['items'] && blank($data['notes'] ?? null)) {
                $order?->items()->delete();
                $order?->delete();

                return null;
            }
            if (! $order) {
                $order = TeaOrder::create(['status' => 'aperto', 'user_id' => $userId]);
                $order->update(['reference' => 'ORIO-' . $order->id]);
            }
            $order->update(['notes' => $data['notes'] ?? null, 'updated_by' => $userId]);
            $this->fillItems($order, $data['items'], $perBox);

            return $order;
        });

        return response()->json(['saved_at' => $order?->updated_at->format('H:i'), 'by' => $request->user()->name]);
    }

    /** Righe dell'ordine dalle scatole (stesso prodotto sommato); i pezzi con le scatole di teaerp. */
    private function fillItems(TeaOrder $order, array $items, array $perBox): void
    {
        $boxes = [];
        foreach ($items as $item) {
            $boxes[$item['product_id']] = ($boxes[$item['product_id']] ?? 0) + (int) $item['boxes'];
        }
        $order->items()->delete();
        $products = Product::whereIn('id', array_keys($boxes))->get()->keyBy('id');
        foreach ($boxes as $productId => $count) {
            $product = $products[$productId];
            $order->items()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => trim($product->name),
                'boxes' => $count,
                'qty' => $count * ($perBox[trim((string) $product->sku)] ?? 1),
                'stock_at_order' => $product->stock_qty,
            ]);
        }
    }

    public function store(Request $request, TeaOrderClient $client)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:500',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.boxes' => 'required|integer|min:1|max:9999',
            'items.*.pcs_per_box' => 'nullable|integer|min:1|max:9999',
            'notes' => 'nullable|string|max:500',
        ], ['items.required' => 'Aggiungi almeno un prodotto.']);
        $this->rememberPerBox($data['items'], $client);

        // I pezzi li calcola il server con le scatole di teaerp, non il browser
        try {
            $perBox = $client->productBoxes();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // Si invia l'ordine in preparazione (con le righe che l'operatrice vede adesso); chi invia ne diventa l'autore
        $order = DB::transaction(function () use ($data, $request, $perBox) {
            $order = TeaOrder::open() ?? TeaOrder::create(['status' => 'bozza', 'user_id' => $request->user()->id]);
            $order->update([
                'status' => 'bozza', 'reference' => 'ORIO-' . $order->id, 'notes' => $data['notes'] ?? null,
                'user_id' => $request->user()->id, 'updated_by' => $request->user()->id,
            ]);
            $this->fillItems($order, $data['items'], $perBox);

            return $order->load('items');
        });

        return $this->deliver($order, $client);
    }

    /** Nuovo tentativo di un ordine non arrivato: stesso riferimento, teaerp non crea doppioni. */
    public function resend(TeaOrder $teaOrder, TeaOrderClient $client)
    {
        abort_if($teaOrder->status === 'inviato', 422, 'Ordine già inviato a TEA.');

        return $this->deliver($teaOrder, $client);
    }

    /**
     * Un ordine rifiutato da TEA (es. un codice che TEA non conosce) torna in preparazione per
     * correggerlo e reinviarlo. Se intanto ne e' stato aperto un altro, le righe si uniscono a quello.
     */
    public function reopen(Request $request, TeaOrder $teaOrder)
    {
        abort_if(in_array($teaOrder->status, ['inviato', 'aperto'], true), 422, 'Questo ordine non si può modificare.');

        DB::transaction(function () use ($request, $teaOrder) {
            $userId = $request->user()->id;
            $open = TeaOrder::open();
            if (! $open) {
                $teaOrder->update(['status' => 'aperto', 'updated_by' => $userId]);

                return;
            }
            foreach ($teaOrder->items as $item) {
                $same = $open->items()->where('product_id', $item->product_id)->first();
                $same ? $same->update(['boxes' => $same->boxes + $item->boxes, 'qty' => $same->qty + $item->qty])
                      : $item->update(['tea_order_id' => $open->id]);
            }
            $open->update(['error' => $teaOrder->error, 'updated_by' => $userId, 'notes' => $open->notes ?: $teaOrder->notes]);
            $teaOrder->items()->delete();
            $teaOrder->delete();
        });

        return redirect()->route('tea-orders.index')->with('status', "Ordine riaperto: correggi le righe e invialo di nuovo.");
    }

    /** Cancella un ordine mai arrivato a TEA (in preparazione o rifiutato). */
    public function destroy(TeaOrder $teaOrder)
    {
        abort_if($teaOrder->status === 'inviato', 422, 'Ordine già inviato a TEA: non si può cancellare.');

        DB::transaction(function () use ($teaOrder) {
            $teaOrder->items()->delete();
            $teaOrder->delete();
        });

        return redirect()->route('tea-orders.index')->with('status', 'Ordine cancellato.');
    }

    public function refresh(TeaOrder $teaOrder, TeaOrderClient $client)
    {
        abort_unless($teaOrder->status === 'inviato', 422, 'Ordine non ancora inviato.');

        $new = $this->syncFromTea($teaOrder, $client);
        if ($new === null) {
            return response()->json(['ok' => false, 'message' => 'teaerp non risponde: riprova tra poco.', 'tea_status' => $teaOrder->tea_status]);
        }

        return response()->json(['ok' => true, 'message' => match ($new) {
            0 => 'Stato aggiornato: nessun DDT nuovo.',
            1 => 'Stato aggiornato: 1 DDT nuovo da spuntare.',
            default => "Stato aggiornato: {$new} DDT nuovi da spuntare.",
        }, 'tea_status' => $teaOrder->tea_status]);
    }

    /** Modello Excel dell'ordine a scatole: sku, name, pcs_per_box, boxes. */
    public function orderTemplate(\App\Services\Tea\TeaOrderExcel $excel)
    {
        try {
            $writer = new Xlsx($excel->template());
        } catch (\RuntimeException $e) {
            return redirect()->route('tea-orders.index')->with('error', 'Modello non scaricato: ' . $e->getMessage());
        }

        return response()->streamDownload(fn () => $writer->save('php://output'), 'Orio-ordine-TEA-' . now()->format('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Legge il modello compilato e restituisce le righe da mettere nell'ordine (non lo invia). */
    public function orderImport(Request $request, \App\Services\Tea\TeaOrderExcel $excel)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt,ods|max:10240'], ['file.mimes' => 'Carica il file Excel (.xlsx).']);

        try {
            $result = $excel->read($request->file('file'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        if (! $result['rows'] && ! $result['problems']) {
            return response()->json(['message' => 'Nel file non ci sono righe con scatole da ordinare (colonna «boxes»).'], 422);
        }

        return response()->json($result);
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

    /**
     * Pezzi per scatola scritti nella riga dell'ordine: si salvano sul prodotto, ma solo dove
     * teaerp non ha la scatola (quella di teaerp comanda sempre).
     */
    private function rememberPerBox(array $items, TeaOrderClient $client): void
    {
        $given = collect($items)->filter(fn ($i) => ! empty($i['pcs_per_box']))->pluck('pcs_per_box', 'product_id');
        if ($given->isEmpty()) {
            return;
        }
        try {
            $tea = $client->teaBoxes();
        } catch (\RuntimeException) {
            return;
        }
        foreach (Product::whereIn('id', $given->keys())->get() as $product) {
            $pcs = (int) $given[$product->id];
            if (! isset($tea[trim((string) $product->sku)]) && $product->pcs_per_box !== $pcs) {
                $product->update(['pcs_per_box' => $pcs]);
            }
        }
    }

    private array $teaSkus = [];

    private function row(Product $p, int $perBox, int $boxes): array
    {
        return [
            'product_id' => $p->id,
            'sku' => $p->sku,
            'name' => trim($p->name),
            'format' => $p->format,
            'stock' => $p->stock_qty,
            'stock_min' => $p->stock_min,
            'pcs_per_box' => $perBox,
            'pcs_from_tea' => in_array(trim((string) $p->sku), $this->teaSkus, true),
            'boxes' => $boxes,
        ];
    }
}
