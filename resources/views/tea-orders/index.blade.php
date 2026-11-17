<x-layout title="Ordini TEA">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Ordine di riassortimento a TEA</h1>
    </div>

    @unless ($configured)
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-amber-900 ring-1 ring-amber-200">
            Il collegamento a teaerp non è ancora configurato (Gestione → Configurazione → Ordini TEA): puoi preparare l'ordine ma l'invio non andrà a buon fine.
        </p>
    @endunless

    <div x-data="teaOrder(@js($proposal))" class="mb-8 grid gap-5 lg:grid-cols-[1fr_340px]">
        <section class="space-y-4">
            <div class="card p-4">
                <div class="relative">
                    <input x-model="query" @input="onSearch()" @keydown.escape="results = []" class="input" placeholder="Aggiungi un prodotto: cerca per nome, SKU o EAN">
                    <div x-show="results.length" x-cloak class="absolute inset-x-0 top-full z-10 mt-1 max-h-72 overflow-y-auto rounded-xl bg-white shadow-lg ring-1 ring-stone-200">
                        <template x-for="product in results" :key="product.product_id">
                            <button type="button" @click="add(product)" class="flex w-full items-center gap-3 border-t border-stone-100 px-4 py-2 text-left first:border-t-0 hover:bg-brand-50">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold" x-text="product.description"></span>
                                    <span class="block text-xs text-stone-500" x-text="[product.sku, product.format].filter(Boolean).join(' · ')"></span>
                                </span>
                                <span class="text-sm tabular-nums text-stone-500" x-text="`giac. ${fmtQty(product.stock)}`"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="flex items-baseline justify-between px-4 py-3">
                    <h2 class="font-bold">Da ordinare <span class="font-normal text-stone-500" x-show="rows.length" x-text="`· ${rows.length} prodotti, ${fmtQty(totalPieces)} pz`"></span></h2>
                    <span class="text-xs text-stone-500">Proposti i prodotti sotto la scorta minima ({{ $withMinimum }} con scorta impostata)</span>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr><th class="px-4 py-2">Prodotto</th><th class="px-4 py-2 text-right">Giacenza</th><th class="px-4 py-2 text-right">Scorta min.</th><th class="w-32 px-4 py-2 text-right">Da ordinare</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <template x-for="(row, index) in rows" :key="row.product_id">
                            <tr>
                                <td class="px-4 py-2"><div class="font-semibold" x-text="row.name"></div><div class="text-xs text-stone-500" x-text="[row.sku, row.format].filter(Boolean).join(' · ')"></div></td>
                                <td class="px-4 py-2 text-right tabular-nums" :class="row.stock < 0 ? 'text-red-600' : ''" x-text="fmtQty(row.stock)"></td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-500" x-text="row.stock_min === null ? '—' : fmtQty(row.stock_min)"></td>
                                <td class="px-4 py-2">
                                    <input type="number" min="0" step="1" x-model="row.qty" class="input py-1.5 text-right tabular-nums">
                                    <div x-show="boxesNote(row)" x-text="boxesNote(row)" class="mt-0.5 text-right text-xs" :class="boxesNote(row).includes('multiplo') ? 'text-amber-700' : 'text-stone-500'"></div>
                                </td>
                                <td class="px-2 py-2"><button type="button" class="h-9 w-9 rounded-lg text-xl text-stone-400 hover:bg-red-50 hover:text-red-600" @click="rows.splice(index, 1)" aria-label="Togli">×</button></td>
                            </tr>
                        </template>
                        <tr x-show="!rows.length"><td colspan="5" class="px-4 py-8 text-center text-stone-400">Nessun prodotto sotto scorta: aggiungine con la ricerca qui sopra.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="space-y-4">
            <div class="card space-y-3 p-5 lg:sticky lg:top-20">
                <div class="text-sm text-stone-600">
                    <div class="font-semibold text-stone-900">Ritiro in sede</div>
                    {{ $shipping['company'] }}<br>{{ $shipping['street'] }}, {{ $shipping['postcode'] }} {{ $shipping['city'] }} ({{ $shipping['region'] }})
                    <div class="mt-1 text-xs text-stone-500">Fattura a Le Essenze di Elda SRL, prezzi del listino d'acquisto TEA.</div>
                </div>
                <input x-model="notes" maxlength="500" class="input" placeholder="Nota per te (es. riassortimento di novembre)">
                <p x-show="error" x-text="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
                <button type="button" class="btn-primary w-full py-4 text-lg" :disabled="busy || !rows.length" @click="send()" x-text="busy ? 'Invio a TEA…' : 'Invia l\'ordine a TEA'"></button>
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Ordine da Excel</h2>
                <p class="text-xs text-stone-500">Lo stesso modello di emmycasa: nella colonna «boxes» scrivi quante <strong>scatole</strong> ordinare. I pezzi per scatola («pcs_per_box») sono quelli di TEA; nel modello ci sono solo i prodotti che TEA vende a scatole, gli altri si aggiungono a pezzi con la ricerca. Ricaricato, il file riempie la tabella: controlli e invii come sempre.</p>
                <a href="{{ route('tea-orders.template') }}" class="btn-light w-full py-2 text-center">Scarica modello ordine</a>
                <label class="btn-light block w-full cursor-pointer py-2 text-center" :class="importing && 'pointer-events-none opacity-60'">
                    <span x-text="importing ? 'Lettura del file…' : 'Carica ordine compilato'"></span>
                    <input type="file" accept=".xlsx,.xls,.ods,.csv" class="hidden" @change="importExcel($event)">
                </label>
                <p x-show="importInfo" x-text="importInfo" class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800"></p>
                <div x-show="importProblems.length" x-cloak class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    <div class="font-semibold" x-text="`Righe non entrate (${importProblems.length}):`"></div>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4"><template x-for="p in importProblems"><li x-text="p"></li></template></ul>
                </div>
            </div>

            <form method="POST" action="{{ route('tea-orders.minimum.import') }}" enctype="multipart/form-data" class="card space-y-3 p-5">
                @csrf
                <h2 class="font-bold">Scorte minime da Excel</h2>
                <p class="text-xs text-stone-500">Per ogni prodotto: «Scorta minima» (sotto questa giacenza viene proposto qui) e «Quantità riordino» (quanti ordinarne; se vuota, quanti ne mancano alla scorta minima). Cella vuota = nessuna scorta.</p>
                <a href="{{ route('tea-orders.minimum') }}" class="btn-light w-full py-2 text-center">Scarica scorte minime</a>
                <input type="file" name="file" accept=".xlsx,.xls,.ods,.csv" required class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-semibold">
                @error('file')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-light w-full py-2">Carica scorte minime</button>
            </form>
        </aside>
    </div>

    <h2 class="mb-3 text-lg font-bold">Ordini inviati</h2>
    <div class="card overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-4 py-2">Data</th><th class="px-4 py-2">Ordine TEA</th><th class="px-4 py-2">Stato</th><th class="px-4 py-2 text-right">Prodotti</th><th class="px-4 py-2 text-right">Totale</th><th class="px-4 py-2">Da</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-stone-50">
                        <td class="px-4 py-2"><a class="font-semibold text-brand-700" href="{{ route('tea-orders.show', $order) }}">{{ $order->created_at->format('d/m/Y H:i') }}</a></td>
                        <td class="px-4 py-2">{{ $order->tea_increment_id ?? $order->reference }}</td>
                        <td class="px-4 py-2">
                            @if ($order->status === 'inviato')
                                <span class="badge bg-brand-50 text-brand-700">{{ $order->tea_status ?? 'Inviato' }}</span>
                            @else
                                <span class="badge bg-red-50 text-red-700">{{ \App\Models\TeaOrder::STATUSES[$order->status] ?? $order->status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $order->items_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $order->total_cents !== null ? euro($order->total_cents) : '—' }}</td>
                        <td class="px-4 py-2 text-stone-500">{{ $order->user?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-stone-400">Nessun ordine.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
