<x-layout title="Magazzino">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Magazzino del negozio</h1>
        <a href="{{ route('stock.index') }}" class="filter-chip {{ $mode === 'carico' ? 'is-active' : '' }}">Carico merce</a>
        @can('admin')
            <a href="{{ route('stock.inventory') }}" class="filter-chip {{ $mode === 'inventario' ? 'is-active' : '' }}">Inventario</a>
        @endcan
    </div>

    <div x-data="stockEntry(@js($mode))" class="mb-8 grid gap-5 lg:grid-cols-[1fr_340px]">
        <section class="space-y-4">
            <p x-show="message" x-text="message" x-cloak class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-700 ring-1 ring-brand-100"></p>

            {{-- Lettore / ricerca --}}
            <div class="card relative p-3">
                <div class="relative">
                    <input x-ref="scan" x-model="scan" @input="onScanInput()" @keydown.enter.prevent="submitScan()" @keydown.escape="results = []; scan = ''"
                           class="input py-3.5 pl-11 text-lg" placeholder="{{ $mode === 'inventario' ? 'Bip del prodotto da contare' : 'Bip del prodotto arrivato o cerca per nome' }}"
                           autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false">
                    <svg class="pointer-events-none absolute top-1/2 left-3.5 h-6 w-6 -translate-y-1/2 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5v14M7 5v14M11 5v14M14 5v14M18 5v14M21 5v14"/></svg>
                </div>
                <p x-show="error" x-text="error" x-cloak class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>

                <div x-show="results.length || resultsTitle" x-cloak @click.outside="results = []; resultsTitle = null"
                     class="absolute inset-x-3 top-full z-20 mt-1 max-h-[60vh] overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-stone-200">
                    <div x-show="resultsTitle" x-text="resultsTitle" class="px-4 py-2 text-sm font-semibold text-stone-500"></div>
                    <template x-for="product in results" :key="product.product_id">
                        <button type="button" @click="pick(product)" class="flex w-full items-center gap-3 border-t border-stone-100 px-4 py-2.5 text-left hover:bg-brand-50">
                            <img :src="product.image_url" x-show="product.image_url" class="h-11 w-11 rounded-lg object-cover ring-1 ring-stone-200" alt="">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold" x-text="product.description"></div>
                                <div class="text-xs text-stone-500" x-text="[product.sku, product.format, product.ean].filter(Boolean).join(' · ')"></div>
                            </div>
                            <div class="text-sm tabular-nums" :class="product.stock < 0 ? 'text-red-600' : 'text-stone-500'" x-text="`giac. ${fmtQty(product.stock)}`"></div>
                        </button>
                    </template>
                </div>
            </div>

            @if ($mode === 'carico')
                <div class="card p-3">
                    <h2 class="mb-2 px-1 font-bold">Merce arrivata <span class="font-normal text-stone-500" x-show="rows.length" x-text="`· ${fmtQty(totalPieces)} pz`"></span></h2>
                    <div x-show="!rows.length" class="rounded-xl border-2 border-dashed border-stone-200 py-12 text-center text-stone-400">
                        Passa il lettore su ogni pezzo arrivato, oppure su un pezzo e correggi la quantità
                    </div>
                    <template x-for="(row, index) in rows" :key="row.product_id">
                        <div class="flex flex-wrap items-center gap-3 border-t border-stone-100 px-1 py-2.5 first:border-0 md:flex-nowrap">
                            <img :src="row.image_url" x-show="row.image_url" class="h-12 w-12 shrink-0 rounded-lg object-cover ring-1 ring-stone-200" alt="">
                            <div class="min-w-0 flex-1 basis-[calc(100%-4rem)] md:basis-auto">
                                <div class="line-clamp-2 font-semibold leading-tight" x-text="row.description"></div>
                                <div class="text-xs text-stone-500" x-text="`${row.sku} · in negozio ${fmtQty(row.stock)} → ${fmtQty(row.stock + (parseFloat(row.qty) || 0))}`"></div>
                            </div>
                            <div class="flex items-center">
                                <button type="button" @click="changeQty(row, -1)" class="h-11 w-11 rounded-l-xl bg-stone-100 text-xl font-bold hover:bg-stone-200" aria-label="Diminuisci">−</button>
                                <input class="h-11 w-16 border-y border-stone-200 text-center text-lg font-semibold focus:outline-none" inputmode="numeric" x-model="row.qty">
                                <button type="button" @click="changeQty(row, 1)" class="h-11 w-11 rounded-r-xl bg-stone-100 text-xl font-bold hover:bg-stone-200" aria-label="Aumenta">+</button>
                            </div>
                            <button type="button" class="flex h-11 w-9 items-center justify-center rounded-lg text-2xl text-stone-400 hover:bg-red-50 hover:text-red-600" @click="rows.splice(index, 1)" aria-label="Rimuovi riga">×</button>
                        </div>
                    </template>
                </div>
            @else
                <div class="card p-5" x-show="current" x-cloak>
                    <div class="flex items-center gap-4">
                        <img :src="current?.image_url" x-show="current?.image_url" class="h-16 w-16 rounded-lg object-cover ring-1 ring-stone-200" alt="">
                        <div class="min-w-0 flex-1">
                            <div class="font-semibold" x-text="current?.description"></div>
                            <div class="text-sm text-stone-500" x-text="`${current?.sku} · per Orio ce ne sono ${fmtQty(current?.stock ?? 0)}`"></div>
                        </div>
                    </div>
                    <label class="mt-4 block text-sm font-semibold">Quanti ne hai contati?</label>
                    <div class="mt-1 flex gap-2">
                        <input x-ref="counted" x-model="counted" @keydown.enter.prevent="saveCount()" @keydown.escape="current = null; focusScan()"
                               class="input text-center text-2xl font-bold" inputmode="decimal" placeholder="0">
                        <button type="button" class="btn-primary px-6" :disabled="busy || counted === ''" @click="saveCount()">Conferma</button>
                    </div>
                </div>
                <div class="card p-4" x-show="!current">
                    <p class="text-sm text-stone-500">Passa il lettore su un prodotto, scrivi quanti pezzi hai contato e premi Invio. Orio registra la differenza come rettifica.</p>
                </div>
                <div class="card p-4" x-show="log.length" x-cloak>
                    <h2 class="mb-2 font-bold">Contati in questa sessione</h2>
                    <ul class="space-y-1 text-sm">
                        <template x-for="entry in log" :key="entry.key"><li x-text="entry.text"></li></template>
                    </ul>
                </div>
            @endif
        </section>

        <aside class="space-y-4">
            @if ($mode === 'carico')
                <div class="card p-5 lg:sticky lg:top-20">
                    <label class="text-sm font-semibold" for="stock-note">Nota (facoltativa)</label>
                    <input id="stock-note" x-model="note" class="input mt-1" placeholder="es. DDT 123 del 10/11">
                    <button type="button" class="btn-primary mt-4 w-full py-4 text-lg" :disabled="!rows.length || busy" @click="saveLoad()">
                        <span x-text="rows.length ? `Registra carico (${fmtQty(totalPieces)} pz)` : 'Registra carico'"></span>
                    </button>
                </div>
            @endif

            <form method="POST" action="{{ route('stock.excel.import') }}" enctype="multipart/form-data" class="card space-y-3 p-5">
                @csrf
                <input type="hidden" name="mode" value="{{ $mode }}">
                <h2 class="font-bold">{{ $mode === 'carico' ? 'Carico da Excel' : 'Inventario da Excel' }}</h2>
                <p class="text-xs text-stone-500">
                    @if ($mode === 'carico')
                        Scarica il modello, scrivi nella colonna «Quantità» i pezzi arrivati e ricaricalo: si <strong>sommano</strong> alla giacenza. Le righe vuote vengono ignorate.
                    @else
                        Scarica il modello, scrivi nella colonna «Quantità» i pezzi contati e ricaricalo: <strong>sostituiscono</strong> la giacenza. Le righe vuote restano come sono.
                    @endif
                </p>
                <a href="{{ route('stock.excel') }}" class="btn-light w-full py-2 text-center">Scarica modello Excel</a>
                <input type="file" name="file" accept=".xlsx,.xls,.ods,.csv" required class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-semibold">
                <input name="note" class="input" placeholder="{{ $mode === 'carico' ? 'Nota, es. DDT 123 del 10/11' : 'Nota, es. inventario di novembre' }}">
                @error('file')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-primary w-full py-3">{{ $mode === 'carico' ? 'Carica merce dal file' : 'Aggiorna giacenze dal file' }}</button>
            </form>

            <div class="card p-5">
                <h2 class="font-bold">Sotto zero <span class="font-normal text-stone-500">({{ $belowZero->count() }})</span></h2>
                <p class="mb-2 text-xs text-stone-500">Venduti più pezzi di quelli caricati: merce non ancora caricata o giacenza da contare.</p>
                @forelse ($belowZero as $product)
                    <a href="{{ route('products.show', $product) }}" class="flex justify-between gap-3 border-t border-stone-100 py-1.5 text-sm hover:text-brand-700">
                        <span class="truncate">{{ trim($product->name) }}</span>
                        <span class="font-semibold text-red-600 tabular-nums">{{ $product->stockLabel() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-stone-400">Nessuno.</p>
                @endforelse
            </div>
        </aside>
    </div>

    {{-- Registro movimenti --}}
    <div class="mb-3 flex flex-wrap items-end gap-3">
        <h2 class="mr-auto text-lg font-bold">Movimenti</h2>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <select name="tipo" class="input w-auto py-2">
                <option value="">Tutti i tipi</option>
                @foreach (\App\Models\StockMovement::TYPES as $key => $label)
                    <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" name="giorno" value="{{ $day?->format('Y-m-d') }}" class="input w-auto py-2">
            <button class="btn-light py-2">Filtra</button>
        </form>
    </div>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-2">Data</th>
                    <th class="px-4 py-2">Prodotto</th>
                    <th class="px-4 py-2">Tipo</th>
                    <th class="px-4 py-2 text-right">Quantità</th>
                    <th class="px-4 py-2 text-right">Giacenza</th>
                    <th class="px-4 py-2">Chi / nota</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movements as $m)
                    <tr class="border-b border-stone-100 last:border-0">
                        <td class="whitespace-nowrap px-4 py-2 text-stone-500">{{ $m->created_at->format('d/m H:i') }}</td>
                        <td class="px-4 py-2">
                            @if ($m->product)
                                <a href="{{ route('products.show', $m->product) }}" class="hover:text-brand-700 hover:underline">{{ trim($m->product->name) }}</a>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2">
                            {{ $m->typeLabel() }}
                            @if ($m->sale_id)
                                <a href="{{ route('sales.show', $m->sale_id) }}" class="text-brand-700 hover:underline">n. {{ $m->sale_id }}</a>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums {{ $m->qty < 0 ? 'text-red-600' : 'text-brand-700' }}">{{ $m->qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($m->qty, 3, ',', '.'), '0'), ',') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format($m->stock_after, 3, ',', '.'), '0'), ',') }}</td>
                        <td class="px-4 py-2 text-stone-500">{{ $m->user?->name }}{{ $m->note ? ' · ' . $m->note : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-stone-400">Nessun movimento.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($movements->count() === 300)
        <p class="mt-2 text-xs text-stone-500">Sono mostrati gli ultimi 300 movimenti: usa i filtri per cercare più indietro.</p>
    @endif
</x-layout>
