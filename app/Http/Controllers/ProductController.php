<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\BucatoProductSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /** Colonne ordinabili della tabella, nell'ordine in cui le mostra la DataTable. */
    private const COLUMNS = [1 => 'name', 2 => 'sku', 3 => 'ean', 5 => 'stock_qty', 6 => 'site_price_cents', 7 => 'price_cents'];

    public function index(Request $request)
    {
        return view('products.index', [
            'filter' => $request->input('filtro', 'attivi'),
            'inPromo' => Product::where('active', true)->inPromo()->count(),
            'lastSync' => Product::max('synced_at'),
        ]);
    }

    /**
     * Sorgente server-side della DataTable (stesso protocollo di emmycasa): la ricerca viene
     * divisa per spazi e ogni parola deve comparire in almeno uno dei campi del prodotto.
     */
    public function data(Request $request)
    {
        $base = Product::query()->tap(fn ($q) => $this->applyFilter($q, $request->input('filtro', 'attivi')));
        $total = (clone $base)->count();

        $terms = array_filter(preg_split('/\s+/', trim((string) $request->input('search.value'))));
        $filtered = (clone $base)->where(function ($q) use ($terms) {
            foreach ($terms as $term) {
                $q->matchingTerm($term, ['category', 'format']);
            }
        });

        $column = self::COLUMNS[(int) $request->input('order.0.column')] ?? 'name';
        $dir = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $products = (clone $filtered)
            ->orderBy($column, $dir)
            ->offset((int) $request->input('start', 0))
            ->limit(min(200, max(1, (int) $request->input('length', 50))))
            ->get();

        return response()->json([
            'draw' => (int) $request->input('draw'),
            'recordsTotal' => $total,
            'recordsFiltered' => $terms ? $filtered->count() : $total,
            'data' => $products->map(fn (Product $p) => [
                'id' => $p->id,
                'image_url' => $p->image_url,
                'name' => $p->name,
                'sku' => $p->sku,
                'ean' => $p->ean,
                'format' => $p->format,
                'category' => $p->category,
                'active' => $p->active,
                'sold_in_shop' => $p->sold_in_shop,
                'in_stock' => $p->in_stock,
                'site_price_cents' => $p->site_price_cents,
                'price_cents' => $p->price_cents,
                'promo' => $p->promoActive() ? ['price_cents' => $p->promo_price_cents, 'until' => $p->promo_until?->format('d/m')] : null,
                'stock_qty' => $p->stock_qty,
                'to_review' => $p->hasPriceToReview(),
            ]),
        ]);
    }

    /** Scheda prodotto: in pratica la pagina del prodotto su bucatoprofumato.com, piu' i dati di cassa. */
    public function show(Product $product)
    {
        return view('products.show', [
            'product' => $product,
            'components' => $product->components()->orderBy('id')->get()
                ->each(fn ($c) => $c->setRelation('item', Product::where('sku', $c->sku)->first(['id', 'sku', 'name', 'stock_qty']))),
            'movements' => $product->stockMovements()->with('user:id,name')->latest('id')->limit(20)->get(),
            'siteUrl' => 'https://www.bucatoprofumato.com/catalogsearch/result/?q=' . urlencode(trim($product->name)),
        ]);
    }

    /** Nuovo prodotto venduto solo in negozio (es. shopper): non e' sul sito e la sincronizzazione non lo tocca. */
    public function create()
    {
        return view('products.form', ['product' => new Product(['vat_rate' => 22]), 'vatRates' => $this->vatRates()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sku'] = $data['sku'] ?: $this->nextSku();
        $product = Product::create($data + [
            'shop_only' => true, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true,
        ]);

        return redirect()->route('products.show', $product)->with('status', 'Prodotto creato: si vende già in cassa.');
    }

    /** Solo i prodotti creati in Orio: gli altri arrivano dal sito. */
    public function edit(Product $product)
    {
        abort_unless($product->shop_only, 404);

        return view('products.form', ['product' => $product, 'vatRates' => $this->vatRates()]);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($product->shop_only, 404);
        $data = $this->validated($request, $product);
        $data['sku'] = $data['sku'] ?: $product->sku;
        $product->update($data);

        return redirect()->route('products.show', $product)->with('status', 'Prodotto aggiornato.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'price' => ['required', 'regex:/^\d+([.,]\d{1,2})?$/'],
            'vat_rate' => ['required', 'integer', \Illuminate\Validation\Rule::in($this->vatRates())],
            'ean' => ['nullable', 'regex:/^\d{8,14}$/'],
            'sku' => ['nullable', 'string', 'max:64', \Illuminate\Validation\Rule::unique('products', 'sku')->ignore($product)],
            'format' => 'nullable|string|max:60',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:8192',
            'remove_image' => 'nullable|boolean',
        ], [
            'image.image' => 'Il file della foto non è un\'immagine.',
            'image.mimes' => 'La foto deve essere JPG, PNG o WEBP.',
            'image.max' => 'La foto è troppo grande (massimo 8 MB).',
            'price.regex' => 'Prezzo non valido: ad esempio 2,50.',
            'ean.regex' => 'Il codice a barre deve avere da 8 a 14 cifre.',
            'sku.unique' => 'Questo codice è già usato da un altro prodotto.',
        ], ['name' => 'nome', 'price' => 'prezzo', 'vat_rate' => 'IVA', 'ean' => 'codice a barre', 'sku' => 'codice']);

        // Lo stesso codice a barre su due prodotti farebbe scegliere ogni volta in cassa
        if ($data['ean'] ?? null) {
            $other = Product::whereBarcode($data['ean'])->when($product, fn ($q) => $q->whereKeyNot($product->id))->first();
            if ($other) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ean' => "Codice a barre già usato da «" . trim($other->name) . "»."]);
            }
        }

        $data['price_cents'] = (int) round((float) str_replace(',', '.', $data['price']) * 100);
        unset($data['price']);
        $data['sku'] = trim((string) ($data['sku'] ?? ''));

        // Foto caricata in Orio (i prodotti del sito hanno quella di bucatoprofumato.com)
        $old = $product?->image_url;
        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $data['image_url'] = $request->hasFile('image')
                ? '/storage/' . $request->file('image')->store('products', 'public')
                : null;
            if ($old && str_contains($old, '/storage/products/')) {
                Storage::disk('public')->delete('products/' . basename($old));
            }
        }
        unset($data['image'], $data['remove_image']);

        return $data;
    }

    /** ORIO-0001, ORIO-0002... per i prodotti creati senza codice. */
    private function nextSku(): string
    {
        $last = Product::where('sku', 'like', 'ORIO-____')->pluck('sku')
            ->map(fn ($s) => (int) substr($s, 5))->max() ?? 0;

        return sprintf('ORIO-%04d', $last + 1);
    }

    /** Aliquote con un reparto programmato sulla stampante fiscale. */
    private function vatRates(): array
    {
        $rates = array_keys(config('orio.fiscal_printer.departments') ?: []);

        return $rates ?: [22];
    }

    private function applyFilter($query, string $filter): void
    {
        match ($filter) {
            'disattivi' => $query->where('active', false),
            'prezzi' => $query->where('active', true)->priceToReview(),
            'sottozero' => $query->where('stock_qty', '<', 0),
            'promo' => $query->where('active', true)->inPromo(),
            'negozio' => $query->where('active', true)->where('sold_in_shop', true),
            'esclusi' => $query->where('sold_in_shop', false),
            'tutti' => null,
            default => $query->where('active', true),
        };
    }

    public function sync(Request $request, BucatoProductSyncService $service)
    {
        // L'export del sito puo' richiedere diversi minuti: niente limite di tempo PHP
        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $s = $service->sync();
        } catch (\Throwable $e) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sincronizzazione non riuscita: ' . $e->getMessage()], 502)
                : back()->with('error', $e->getMessage());
        }

        $message = "Sincronizzazione completata: {$s['created']} nuovi, {$s['updated']} aggiornati, {$s['deactivated']} disattivati, {$s['price_changes']} prezzi cambiati sul sito.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'stats' => $s,
                'to_review' => Product::where('active', true)->priceToReview()->count(),
                'last_sync' => now()->format('d/m/Y H:i'),
            ]);
        }

        return back()->with('status', $message);
    }

    /** Interruttore "in vendita in negozio" (solo admin). */
    public function updateShop(Request $request, Product $product)
    {
        $product->update(['sold_in_shop' => $request->validate(['sold_in_shop' => 'required|boolean'])['sold_in_shop']]);

        return response()->json(['sold_in_shop' => $product->sold_in_shop]);
    }

    public function updatePrice(Request $request, Product $product)
    {
        $price = $request->validate(['price' => ['required', 'regex:/^\d+([.,]\d{1,2})?$/']])['price'];

        $product->update(['price_cents' => (int) round((float) str_replace(',', '.', $price) * 100)]);

        return response()->json(['product' => $product, 'to_review' => $product->hasPriceToReview()]);
    }

    /**
     * Prezzo promo del negozio con periodo (dal giorno, facoltativo = da subito, fino al giorno
     * compreso). Fuori dal periodo la cassa torna da sola al prezzo Orio. Prezzo vuoto = togli la promo.
     */
    public function updatePromo(Request $request, Product $product)
    {
        if (! $request->filled('promo_price')) {
            $product->update(['promo_price_cents' => null, 'promo_from' => null, 'promo_until' => null]);

            return back()->with('status', 'Promo tolta.');
        }

        $data = $request->validate([
            'promo_price' => ['required', 'regex:/^\d+([.,]\d{1,2})?$/'],
            'promo_from' => 'nullable|date',
            'promo_until' => 'required|date|after_or_equal:today|after_or_equal:promo_from',
        ], [
            'promo_price.regex' => 'Prezzo promo non valido: ad esempio 9,90.',
            'promo_until.required' => 'Scrivi fino a quando vale la promo.',
            'promo_until.after_or_equal' => 'La fine della promo non può essere nel passato o prima dell\'inizio.',
        ]);

        $cents = (int) round((float) str_replace(',', '.', $data['promo_price']) * 100);
        if ($cents <= 0 || $cents >= $product->price_cents) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promo_price' => 'Il prezzo promo deve essere più basso del prezzo Orio (' . euro($product->price_cents) . ').']);
        }

        $product->update(['promo_price_cents' => $cents, 'promo_from' => $data['promo_from'] ?? null, 'promo_until' => $data['promo_until']]);

        return back()->with('status', 'Promo salvata.');
    }

    /** Riporta il prezzo in cassa a quello attuale del sito (uno o tutti i prodotti da rivedere). */
    public function alignPrice(Request $request, ?Product $product = null)
    {
        $query = $product
            ? Product::whereKey($product->id)
            : Product::where('active', true)->priceToReview();

        $count = $query->update(['price_cents' => \DB::raw('site_price_cents')]);

        if ($request->expectsJson()) {
            return response()->json(['product' => $product?->fresh(), 'count' => $count]);
        }

        return back()->with('status', "Prezzi allineati al sito: {$count}.");
    }

    /**
     * Bip del lettore: cerca il codice tra i prodotti attivi. Piu' risultati (es. varianti con
     * lo stesso EAN) vengono restituiti all'operatore per la scelta.
     */
    public function barcode(string $code)
    {
        $products = Product::sellable()->whereBarcode(trim($code))->limit(5)->get();

        if ($products->isEmpty()) {
            $excluded = Product::where('active', true)->where('sold_in_shop', false)->whereBarcode(trim($code))->first();

            return response()->json(['message' => $excluded
                ? "«" . trim($excluded->name) . "» non è in vendita in negozio."
                : "Nessun prodotto attivo con codice {$code}."], 404);
        }

        return response()->json(['products' => $products->map->toCartItem()]);
    }

    public function search(Request $request)
    {
        $terms = array_filter(preg_split('/\s+/', trim((string) $request->input('q'))));

        if (! $terms) {
            return response()->json(['products' => []]);
        }

        $products = Product::sellable()
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->matchingTerm($term);
                }
            })
            ->with('components')
            // Cesti per primi: cercando un prodotto molto diffuso (es. "card") resterebbero
            // oltre il limite dei 12 risultati
            ->withExists('components')
            ->orderByDesc('components_exists')
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json(['products' => $products->map->toCartItem()]);
    }
}
