<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\BucatoProductSyncService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /** Colonne ordinabili della tabella, nell'ordine in cui le mostra la DataTable. */
    private const COLUMNS = [1 => 'name', 2 => 'sku', 3 => 'ean', 4 => 'site_price_cents', 5 => 'price_cents'];

    public function index(Request $request)
    {
        return view('products.index', [
            'filter' => $request->input('filtro', 'attivi'),
            'toReview' => Product::where('active', true)->priceToReview()->count(),
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
                $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('ean', 'like', "%{$term}%")
                    ->orWhere('category', 'like', "%{$term}%")
                    ->orWhere('format', 'like', "%{$term}%"));
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
                'in_stock' => $p->in_stock,
                'site_price_cents' => $p->site_price_cents,
                'price_cents' => $p->price_cents,
                'to_review' => $p->hasPriceToReview(),
            ]),
        ]);
    }

    /** Scheda prodotto: in pratica la pagina del prodotto su bucatoprofumato.com, piu' i dati di cassa. */
    public function show(Product $product)
    {
        return view('products.show', [
            'product' => $product,
            'siteUrl' => 'https://www.bucatoprofumato.com/catalogsearch/result/?q=' . urlencode(trim($product->name)),
        ]);
    }

    private function applyFilter($query, string $filter): void
    {
        match ($filter) {
            'disattivi' => $query->where('active', false),
            'prezzi' => $query->where('active', true)->priceToReview(),
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

    public function updatePrice(Request $request, Product $product)
    {
        $price = $request->validate(['price' => ['required', 'regex:/^\d+([.,]\d{1,2})?$/']])['price'];

        $product->update(['price_cents' => (int) round((float) str_replace(',', '.', $price) * 100)]);

        return response()->json(['product' => $product, 'to_review' => $product->hasPriceToReview()]);
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
        $products = Product::where('active', true)->whereBarcode(trim($code))->limit(5)->get();

        if ($products->isEmpty()) {
            return response()->json(['message' => "Nessun prodotto attivo con codice {$code}."], 404);
        }

        return response()->json(['products' => $products->map->toCartItem()]);
    }

    public function search(Request $request)
    {
        $terms = array_filter(preg_split('/\s+/', trim((string) $request->input('q'))));

        if (! $terms) {
            return response()->json(['products' => []]);
        }

        $products = Product::where('active', true)
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('ean', 'like', "%{$term}%"));
                }
            })
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json(['products' => $products->map->toCartItem()]);
    }
}
