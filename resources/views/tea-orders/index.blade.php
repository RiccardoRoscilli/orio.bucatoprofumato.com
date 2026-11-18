<x-layout title="Ordini TEA">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Ordine di riassortimento a TEA</h1>
    </div>

    @unless ($configured)
        <p class="mb-4 rounded-xl bg-amber-50 px-4 py-3 text-amber-900 ring-1 ring-amber-200">
            Il collegamento a teaerp non è ancora configurato (Gestione → Configurazione → Ordini TEA): puoi preparare l'ordine ma l'invio non andrà a buon fine.
        </p>
    @endunless

    @if ($boxesError)
        <p class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-red-800 ring-1 ring-red-200">
            Non ho i pezzi per scatola da teaerp ({{ $boxesError }}): le scatole qui sotto sono da 1 pezzo e l'ordine non si può inviare. Ricarica la pagina tra poco.
        </p>
    @endif

    <div x-data="teaOrder(@js($proposal), @js((object) $boxes), @js($open ? ['notes' => $open->notes, 'saved_at' => $open->updated_at->format('d/m H:i'), 'by' => $open->editor?->name] : null), @js($suggestions))" class="mb-8 grid gap-5 lg:grid-cols-[1fr_340px]">
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
                    <h2 class="font-bold">Da ordinare <span class="font-normal text-stone-500" x-show="rows.length" x-text="`· ${rows.length} prodotti, ${totalBoxes} box, ${fmtQty(totalPieces)} pz`"></span></h2>
                    <span class="text-xs" :class="saveState === 'error' ? 'font-semibold text-red-700' : 'text-stone-500'" x-text="saveLabel"></span>
                </div>
                <table class="w-full text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr><th class="px-4 py-2">Prodotto</th><th class="px-4 py-2 text-right">Giacenza</th><th class="px-4 py-2 text-right">Scorta</th><th class="w-28 px-4 py-2 text-right">Box</th><th class="px-4 py-2 text-right">Pz per box</th><th class="px-4 py-2 text-right">Totale pz</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <template x-for="(row, index) in rows" :key="row.product_id">
                            <tr>
                                <td class="px-4 py-2"><div class="font-semibold" x-text="row.name"></div><div class="text-xs text-stone-500" x-text="[row.sku, row.format].filter(Boolean).join(' · ')"></div></td>
                                <td class="px-4 py-2 text-right tabular-nums" :class="row.stock < 0 ? 'text-red-600' : ''" x-text="fmtQty(row.stock)"></td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-500" x-text="row.stock_min === null ? '—' : fmtQty(row.stock_min)"></td>
                                <td class="px-4 py-2">
                                    <input type="number" min="0" step="1" x-model="row.boxes" class="input py-1.5 text-right tabular-nums" aria-label="Scatole">
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-stone-500" x-text="row.pcs_per_box"></td>
                                <td class="px-4 py-2 text-right font-semibold tabular-nums" x-text="fmtQty(pieces(row))"></td>
                                <td class="px-2 py-2"><button type="button" class="h-9 w-9 rounded-lg text-xl text-stone-400 hover:bg-red-50 hover:text-red-600" @click="rows.splice(index, 1)" aria-label="Togli">×</button></td>
                            </tr>
                        </template>
                        <tr x-show="!rows.length"><td colspan="7" class="px-4 py-8 text-center text-stone-400">Nessun prodotto nell'ordine: aggiungili con la ricerca qui sopra.</td></tr>
                    </tbody>
                </table>
                <p class="border-t border-stone-100 px-4 py-2 text-xs text-stone-500">
                    L'ordine si salva da solo a ogni modifica e resta aperto, anche per le colleghe, finché qualcuno non lo invia a TEA.
                </p>
            </div>

            {{-- Sotto scorta ma non ancora nell'ordine in preparazione --}}
            <div x-show="suggestions.length" x-cloak class="card p-4">
                <h2 class="font-bold">Sotto scorta, non ancora nell'ordine <span class="font-normal text-stone-500" x-text="`(${suggestions.length})`"></span></h2>
                <div class="mt-2 divide-y divide-stone-100">
                    <template x-for="product in suggestions" :key="product.product_id">
                        <div class="flex items-center gap-3 py-2 text-sm">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold" x-text="product.description"></span>
                                <span class="text-xs text-stone-500" x-text="`${product.sku} · giacenza ${fmtQty(product.stock)}, scorta ${fmtQty(product.stock_min)}`"></span>
                            </span>
                            <button type="button" class="btn-light shrink-0 py-1.5 text-sm" @click="add(product)">+ Aggiungi</button>
                        </div>
                    </template>
                </div>
            </div>
        </section>

        <aside class="space-y-4">
            <div class="card space-y-3 p-5">
                <div class="text-sm text-stone-600">
                    <div class="font-semibold text-stone-900">Ritiro in sede</div>
                    {{ $shipping['company'] }}<br>{{ $shipping['street'] }}, {{ $shipping['postcode'] }} {{ $shipping['city'] }} ({{ $shipping['region'] }})
                    <div class="mt-1 text-xs text-stone-500">Fattura a Le Essenze di Elda SRL, prezzi del listino d'acquisto TEA.</div>
                </div>
                <input x-model="notes" maxlength="500" class="input" placeholder="Nota interna (es. riassortimento di novembre)">
                <p x-show="error" x-text="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
                <button type="button" class="btn-primary w-full py-4 text-lg" :disabled="busy || !rows.length" @click="send()" x-text="busy ? 'Invio a TEA…' : 'Invia l\'ordine a TEA'"></button>
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Ordine da Excel</h2>
                <p class="text-xs text-stone-500">Nella colonna «boxes» scrivi quante <strong>scatole</strong> ordinare; i pezzi per scatola («pcs_per_box») sono quelli di TEA. Ricaricato, il file riempie l'ordine: controlli e invii come sempre.</p>
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

            @can('admin')
            <form method="POST" action="{{ route('tea-orders.minimum.import') }}" enctype="multipart/form-data" class="card space-y-3 p-5">
                @csrf
                <h2 class="font-bold">Scorte minime da Excel</h2>
                <p class="text-xs text-stone-500">Per ogni prodotto: «Scorta minima» (sotto questa giacenza viene proposto qui) e «Quantità riordino» (quanti ordinarne; se vuota, quanti ne mancano alla scorta minima). Cella vuota = nessuna scorta.</p>
                <a href="{{ route('tea-orders.minimum') }}" class="btn-light w-full py-2 text-center">Scarica scorte minime</a>
                <input type="file" name="file" accept=".xlsx,.xls,.ods,.csv" required class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-semibold">
                @error('file')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <button class="btn-light w-full py-2">Carica scorte minime</button>
            </form>
            @endcan
        </aside>
    </div>

    <h2 class="mb-3 text-lg font-bold">Ordini inviati</h2>
    @php
        $tones = ['attesa' => 'bg-sky-50 text-sky-800', 'spuntare' => 'bg-amber-100 text-amber-800', 'in_parte' => 'bg-amber-100 text-amber-800', 'spuntato' => 'bg-amber-50 text-amber-900', 'confermato' => 'bg-brand-50 text-brand-700', 'errore' => 'bg-red-50 text-red-700'];
    @endphp
    {{-- Lo stato dell'arrivo si aggiorna dopo il caricamento chiedendo a teaerp i DDT nuovi --}}
    <div class="card overflow-x-auto" x-data="{ arrivals: @js($orders->mapWithKeys(fn ($o) => [$o->id => $o->arrival()])), tones: @js($tones), checking: true }"
         x-init="axios.post(@js(route('tea-orders.arrivals'))).then(({ data }) => Object.assign(arrivals, data)).finally(() => checking = false)">
        <table class="w-full text-left text-sm">
            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-4 py-2">Data</th><th class="px-4 py-2">Ordine TEA</th><th class="px-4 py-2">Merce <span x-show="checking" class="font-normal normal-case text-stone-400">· controllo i DDT…</span></th><th class="px-4 py-2 text-right">Prodotti</th><th class="px-4 py-2 text-right">Totale</th><th class="px-4 py-2">Da</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($orders as $order)
                    <tr class="hover:bg-stone-50">
                        <td class="px-4 py-2"><a class="font-semibold text-brand-700" href="{{ route('tea-orders.show', $order) }}">{{ $order->created_at->format('d/m/Y H:i') }}</a></td>
                        <td class="px-4 py-2">{{ $order->tea_increment_id ?? $order->reference }}</td>
                        <td class="px-4 py-2">
                            <span class="badge" :class="tones[arrivals[{{ $order->id }}].key]" x-text="arrivals[{{ $order->id }}].label">{{ $order->arrival()['label'] }}</span>
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
