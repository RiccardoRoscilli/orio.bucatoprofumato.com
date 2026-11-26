<x-layout :title="$product->name">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('products.index') }}"
       class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-stone-600 hover:text-stone-900">← Prodotti</a>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        {{-- Foto --}}
        <div class="card flex aspect-square items-center justify-center overflow-hidden p-6 lg:sticky lg:top-5 lg:self-start">
            @if ($components->isNotEmpty())
                <span class="flex h-40 w-40 items-center justify-center rounded-3xl bg-brand-50 text-brand-700 ring-1 ring-brand-200">
                    <x-basket-icon class="h-20 w-20" />
                </span>
            @elseif ($product->image_url)
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
            <div class="mt-1 flex items-start gap-3">
                <h1 class="mr-auto text-2xl font-bold sm:text-3xl">{{ trim($product->name) }}</h1>
                @if ($product->shop_only)
                    @can('admin')
                        <a href="{{ route('products.edit', $product) }}" class="btn-light shrink-0 py-2">Modifica</a>
                    @endcan
                @endif
            </div>

            <div class="mt-3 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                @if ($product->promoActive())
                    <span class="text-3xl font-bold tabular-nums text-brand-700">{{ euro($product->promo_price_cents) }}</span>
                    <span class="text-lg tabular-nums text-stone-400 line-through">{{ euro($product->price_cents) }}</span>
                    <span class="badge bg-brand-50 text-brand-700">In promo fino al {{ $product->promo_until->format('d/m/Y') }}</span>
                @else
                    <span class="text-3xl font-bold tabular-nums">{{ euro($product->price_cents) }}</span>
                @endif
                @if ($product->hasPriceToReview())
                    <span class="text-sm text-amber-700">sul sito {{ euro($product->site_price_cents) }}</span>
                @endif
            </div>

            @can('admin')
                @php
                    $promoScheduled = $product->promo_price_cents !== null && ! $product->promoActive() && $product->promo_from?->isFuture();
                @endphp
                <form method="POST" action="{{ route('products.promo', $product) }}" class="mt-4 rounded-xl bg-stone-50 p-3 ring-1 ring-stone-200">
                    @csrf
                    @method('PUT')
                    <div class="mb-2 text-sm font-semibold">
                        Promo in negozio
                        @if ($promoScheduled)
                            <span class="ml-1 font-normal text-stone-500">· parte il {{ $product->promo_from->format('d/m/Y') }}</span>
                        @endif
                    </div>
                    @if ($errors->any())
                        <p class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
                    @endif
                    <div class="flex flex-wrap items-end gap-3 text-sm">
                        <label class="block">Prezzo promo (€)
                            <input name="promo_price" value="{{ old('promo_price', $product->promo_price_cents !== null ? number_format($product->promo_price_cents / 100, 2, ',', '') : '') }}" inputmode="decimal" class="input mt-1 w-28 py-1.5 text-right tabular-nums" placeholder="9,90">
                        </label>
                        <label class="block">Dal
                            <input type="date" name="promo_from" value="{{ old('promo_from', $product->promo_from?->toDateString()) }}" class="input mt-1 py-1.5">
                        </label>
                        <label class="block">Al (compreso)
                            <input type="date" name="promo_until" value="{{ old('promo_until', $product->promo_until?->toDateString()) }}" class="input mt-1 py-1.5">
                        </label>
                        <button class="btn-primary py-2">Salva promo</button>
                        @if ($product->promo_price_cents !== null)
                            <button name="promo_price" value="" class="py-2 font-semibold text-red-700 hover:text-red-900">Togli promo</button>
                        @endif
                    </div>
                    <p class="mt-2 text-xs text-stone-500">Senza «Dal» parte subito. Finito il periodo la cassa torna da sola al prezzo Orio.</p>
                </form>
            @endcan

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="badge {{ $product->stock_qty < 0 ? 'bg-red-50 text-red-700' : ($product->stock_qty > 0 ? 'bg-brand-50 text-brand-700' : 'bg-stone-100 text-stone-600') }}">In negozio: {{ $product->stockLabel() }}</span>
                @if ($product->shop_only)
                    <span class="badge bg-brand-50 text-brand-700">Solo negozio: creato in Orio, non è sul sito</span>
                @endif
                @if ($product->active && ! $product->sold_in_shop)
                    <span class="badge bg-stone-200 text-stone-700">Non venduto in negozio</span>
                @endif
                @unless ($product->active)
                    <span class="badge bg-red-50 text-red-700">Non attivo sul sito: non si vende in cassa</span>
                @endunless
                @if ($product->active && ! $product->in_stock)
                    <span class="badge bg-amber-100 text-amber-800">Esaurito sul sito</span>
                @endif
            </div>

            @can('admin')
                <form method="POST" action="{{ route('products.minimum', $product) }}" class="mt-4 flex flex-wrap items-end gap-3 rounded-xl bg-stone-50 p-3 text-sm">
                    @csrf @method('PATCH')
                    <label class="block">Scorta minima
                        <input name="stock_min" type="number" min="0" step="1" value="{{ $product->stock_min !== null ? (float) $product->stock_min : '' }}" class="input mt-1 w-28 py-1.5">
                    </label>
                    <label class="block">Quantità riordino
                        <input name="reorder_qty" type="number" min="0" step="1" value="{{ $product->reorder_qty !== null ? (float) $product->reorder_qty : '' }}" class="input mt-1 w-28 py-1.5">
                    </label>
                    <button class="btn-light py-2">Salva</button>
                    <span class="text-xs text-stone-500">Sotto la scorta minima il prodotto viene proposto nell'ordine a TEA.</span>
                </form>
            @endcan

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

            @if ($components->isNotEmpty())
                <div class="card mt-6 p-5">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">Contenuto del cesto</h2>
                    <ul class="divide-y divide-stone-100 text-sm">
                        @foreach ($components as $c)
                            <li class="flex items-baseline gap-3 py-2">
                                <span class="w-12 shrink-0 text-right font-bold tabular-nums">{{ rtrim(rtrim(number_format($c->qty, 3, ',', ''), '0'), ',') }} ×</span>
                                <span class="min-w-0 flex-1">
                                    @if ($c->item)
                                        <a href="{{ route('products.show', $c->item) }}" class="font-semibold hover:text-brand-700 hover:underline">{{ trim($c->name) }}</a>
                                    @else
                                        <span class="font-semibold">{{ trim($c->name) }}</span>
                                    @endif
                                    <span class="block text-xs text-stone-500">{{ $c->sku }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($product->description)
                <div class="card mt-6 p-5">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">Dettagli</h2>
                    <div class="product-text">{!! $product->description !!}</div>
                </div>
            @endif

            @if ($movements->isNotEmpty())
                <div class="card mt-6 p-5">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">Ultimi movimenti di magazzino</h2>
                    <table class="w-full text-sm">
                        @foreach ($movements as $m)
                            <tr class="border-t border-stone-100 first:border-0">
                                <td class="py-1.5 pr-3 whitespace-nowrap text-stone-500">{{ $m->created_at->format('d/m H:i') }}</td>
                                <td class="py-1.5 pr-3">{{ $m->typeLabel() }}@if ($m->sale_id) <a href="{{ route('sales.show', $m->sale_id) }}" class="text-brand-700 hover:underline">n. {{ $m->sale_id }}</a>@endif{{ $m->note ? ' · ' . $m->note : '' }}</td>
                                <td class="py-1.5 pr-3 text-right font-semibold tabular-nums {{ $m->qty < 0 ? 'text-red-600' : 'text-brand-700' }}">{{ $m->qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($m->qty, 3, ',', '.'), '0'), ',') }}</td>
                                <td class="py-1.5 text-right tabular-nums text-stone-500">= {{ rtrim(rtrim(number_format($m->stock_after, 3, ',', '.'), '0'), ',') }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @endif

            @if (! $product->shop_only && ! $product->short_description && ! $product->description)
                <p class="mt-6 rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-600">Sul sito questo prodotto non ha descrizione.</p>
            @endif

            <div class="mt-6 flex flex-wrap items-center gap-3 text-sm text-stone-500">
                @unless ($product->shop_only)
                <a href="{{ $siteUrl }}" target="_blank" rel="noopener" class="btn-light py-2">Cerca sul sito ↗</a>
                @endunless
                @if ($product->synced_at)
                    <span>Dati del sito aggiornati al {{ $product->synced_at->format('d/m/Y H:i') }}</span>
                @endif
            </div>
        </div>
    </div>
</x-layout>
