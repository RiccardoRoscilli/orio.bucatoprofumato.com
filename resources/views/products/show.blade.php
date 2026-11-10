<x-layout :title="$product->name">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('products.index') }}"
       class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-stone-600 hover:text-stone-900">← Prodotti</a>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        {{-- Foto --}}
        <div class="card flex aspect-square items-center justify-center overflow-hidden p-6 lg:sticky lg:top-5 lg:self-start">
            @if ($product->image_url)
                <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="max-h-full max-w-full object-contain">
            @else
                <span class="text-sm text-stone-400">Nessuna foto sul sito</span>
            @endif
        </div>

        {{-- Testi e dati --}}
        <div>
            @if ($product->category)
                <div class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $product->category }}</div>
            @endif
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">{{ trim($product->name) }}</h1>

            <div class="mt-3 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span class="text-3xl font-bold tabular-nums">{{ euro($product->price_cents) }}</span>
                @if ($product->hasPriceToReview())
                    <span class="text-sm text-amber-700">sul sito {{ euro($product->site_price_cents) }}</span>
                @endif
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                @unless ($product->active)
                    <span class="badge bg-red-50 text-red-700">Non attivo sul sito: non si vende in cassa</span>
                @endunless
                @if ($product->active && ! $product->in_stock)
                    <span class="badge bg-amber-100 text-amber-800">Esaurito sul sito</span>
                @endif
            </div>

            @if ($product->short_description)
                <div class="product-text mt-5">{!! $product->short_description !!}</div>
            @endif

            <dl class="mt-6 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 border-t border-stone-200 pt-4 text-sm">
                <dt class="text-stone-500">SKU</dt>
                <dd>{{ $product->sku }}</dd>
                @if ($product->ean)
                    <dt class="text-stone-500">EAN</dt>
                    <dd class="font-mono">{{ $product->ean }}</dd>
                @endif
                @if ($product->format)
                    <dt class="text-stone-500">Capacità</dt>
                    <dd>{{ $product->format }}</dd>
                @endif
                @if ($product->categories)
                    <dt class="text-stone-500">Categorie</dt>
                    <dd>{{ $product->categories }}</dd>
                @endif
                <dt class="text-stone-500">IVA</dt>
                <dd>{{ $product->vat_rate }}%</dd>
            </dl>

            @if ($product->description)
                <div class="card mt-6 p-5">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">Dettagli</h2>
                    <div class="product-text">{!! $product->description !!}</div>
                </div>
            @endif

            @if (! $product->short_description && ! $product->description)
                <p class="mt-6 rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-600">Sul sito questo prodotto non ha descrizione.</p>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-3 text-sm text-stone-500">
                <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="btn-light py-2">Cerca sul sito ↗</a>
                @if ($product->synced_at)
                    <span>Dati del sito aggiornati al {{ $product->synced_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
    </div>
</x-layout>
