<x-layout :title="'Ordine TEA ' . ($order->tea_increment_id ?? $order->reference)">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('tea-orders.index') }}" class="text-sm font-semibold text-brand-700">← Ordini TEA</a>
        <h1 class="text-xl font-bold">Ordine {{ $order->tea_increment_id ?? $order->reference }}</h1>
        @if ($order->status === 'inviato')
            <span class="badge bg-brand-50 text-brand-700">{{ $order->tea_status ?? 'Inviato' }}</span>
        @else
            <span class="badge bg-red-50 text-red-700">{{ \App\Models\TeaOrder::STATUSES[$order->status] ?? $order->status }}</span>
        @endif
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_340px]" x-data="{ busy: false, msg: null, async post(url) { this.busy = true; this.msg = null; try { const { data } = await axios.post(url); this.msg = data.message; setTimeout(() => location.reload(), 900); } catch (e) { this.msg = e.response?.data?.message ?? e.message; this.busy = false; } } }">
        <section class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr><th class="px-4 py-2">Prodotto</th><th class="px-4 py-2 text-right">Giacenza all'ordine</th><th class="px-4 py-2 text-right">Q.tà</th><th class="px-4 py-2 text-right">Prezzo</th><th class="px-4 py-2 text-right">Totale</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($order->items as $item)
                        <tr>
                            <td class="px-4 py-2"><div class="font-semibold">{{ $item->name }}</div><div class="text-xs text-stone-500">{{ $item->sku }}</div></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $item->stock_at_order === null ? '—' : rtrim(rtrim(number_format($item->stock_at_order, 3, ',', ''), '0'), ',') }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $item->qty }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $item->price_cents !== null ? euro($item->price_cents) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $item->row_total_cents !== null ? euro($item->row_total_cents) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        {{-- Arrivo della merce: spunta delle righe dei DDT di TEA, in magazzino solo alla conferma del carico --}}
        <section class="space-y-4 lg:col-start-1">
            <div>
                <h2 class="text-lg font-bold">Arrivo merce (DDT di TEA)</h2>
                <p class="text-sm text-stone-600">
                    Spunta le righe man mano che controlli i colli: le spunte si salvano, puoi fermarti e riprendere più tardi.
                    Se sono arrivate meno scatole, scrivile prima di spuntare (0 se non è arrivata); finché non confermi si possono correggere.
                    Quando hai spuntato tutto premi «Conferma carico»: solo allora la merce entra in magazzino (scatole × pezzi per scatola) e le quantità non si cambiano più.
                </p>
            </div>

            @forelse ($order->ddts as $ddt)
                @php
                    $fmt = fn ($q) => rtrim(rtrim(number_format((float) $q, 3, ',', ''), '0'), ',');
                @endphp
                <div class="card overflow-x-auto" x-data="{ left: {{ $ddt->items->whereNull('qty_received')->whereNotNull('product_id')->count() }}, confirmed: {{ $ddt->confirmed_at ? 'true' : 'false' }} }" @ddt-row-received="left--">
                    <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                        <div class="mr-auto">
                            <span class="font-bold">DDT {{ $ddt->ddt_number }}</span>
                            @if ($ddt->issued_at)
                                <span class="text-sm text-stone-500">del {{ $ddt->issued_at->format('d/m/Y') }}</span>
                            @endif
                        </div>
                        @if ($ddt->confirmed_at)
                            <span class="badge bg-brand-50 text-brand-700">Carico confermato il {{ $ddt->confirmed_at->format('d/m/Y H:i') }} · {{ $ddt->confirmer?->name }}</span>
                        @else
                            <span x-show="left" x-cloak class="badge bg-amber-100 text-amber-800" x-text="left === 1 ? '1 riga da spuntare' : `${left} righe da spuntare`"></span>
                            <button x-show="!left" x-cloak type="button" class="btn-primary py-2 text-sm" :disabled="busy"
                                    @click="if (await window.askConfirm({ title: 'Confermare il carico?', message: 'Le quantità spuntate entrano adesso in magazzino. Dopo la conferma non si possono più correggere.', confirmLabel: 'Conferma carico' })) post(@js(route('tea-ddts.confirm', $ddt)))">
                                Conferma carico
                            </button>
                        @endif
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                            <tr><th class="px-4 py-2">Prodotto</th><th class="px-4 py-2 text-right">Box spediti</th><th class="w-28 px-4 py-2 text-right">Box ricevuti</th><th class="px-4 py-2 text-right">Pz per box</th><th class="px-4 py-2 text-right">Totale pz</th><th class="px-4 py-2"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($ddt->items as $item)
                                <tr x-data="{
                                        boxes: @js($fmt($item->boxes($item->qty_received ?? $item->qty_shipped))),
                                        saved: @js($item->qty_received === null ? null : $fmt($item->boxes($item->qty_received))),
                                        info: @js($item->received_at ? $item->received_at->format('d/m H:i') . ' · ' . ($item->receiver?->name ?? '') : ''),
                                        shipped: @js($fmt($item->boxes($item->qty_shipped))),
                                        perBox: {{ max(1, $item->pcs_per_box) }},
                                        rowBusy: false, rowError: null,
                                        get pieces() { return (parseInt(this.boxes, 10) || 0) * this.perBox },
                                        async save() {
                                            if (!/^\d+$/.test(String(this.boxes).trim())) { this.rowError = 'Scrivi le scatole intere ricevute.'; return; }
                                            this.rowBusy = true; this.rowError = null;
                                            const first = this.saved === null;
                                            try {
                                                const { data } = await axios.post(@js(route('tea-ddt-items.receive', $item)), { boxes: parseInt(this.boxes, 10) });
                                                this.saved = String(data.item.boxes_received).replace('.', ',');
                                                this.boxes = this.saved;
                                                this.info = data.item.received_at + ' · ' + (data.item.receiver ?? '');
                                                if (first) this.$dispatch('ddt-row-received');
                                            } catch (e) { this.rowError = e.response?.data?.message ?? e.message; }
                                            this.rowBusy = false;
                                        }
                                    }" :class="saved !== null ? (saved !== shipped ? 'bg-amber-50' : 'bg-brand-50/40') : ''">
                                    <td class="px-4 py-2">
                                        <div class="font-semibold">{{ $item->name }}</div>
                                        <div class="text-xs text-stone-500">{{ $item->sku }}</div>
                                        @unless ($item->product_id)
                                            <div class="text-xs font-semibold text-red-700">Non è un prodotto di Orio: non si può caricare.</div>
                                        @endunless
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($item->boxes($item->qty_shipped)) }}</td>
                                    <td class="px-4 py-2">
                                        @if ($item->product_id)
                                            <input x-model="boxes" type="number" min="0" step="1" inputmode="numeric" class="input py-1.5 text-right tabular-nums disabled:bg-stone-50" aria-label="Box ricevuti" @keydown.enter="!confirmed && save()" :disabled="confirmed">
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-stone-500">{{ max(1, $item->pcs_per_box) }}</td>
                                    <td class="px-4 py-2 text-right font-semibold tabular-nums" x-text="pieces"></td>
                                    <td class="whitespace-nowrap px-4 py-2">
                                        @if ($item->product_id)
                                            <button type="button" x-show="saved === null" class="btn-primary py-1.5 text-sm" :disabled="rowBusy" @click="save()">✓ Spunta</button>
                                            <button type="button" x-show="!confirmed && saved !== null && String(boxes) !== saved" x-cloak class="btn-light py-1.5 text-sm" :disabled="rowBusy" @click="save()">Correggi</button>
                                            <div x-show="saved !== null && String(boxes) === saved" x-cloak class="text-xs text-brand-800">
                                                ✓ <span x-text="info"></span>
                                                <span x-show="saved !== shipped" class="block font-semibold text-amber-800" x-text="`differenza ${(parseFloat(saved.replace(',', '.')) - parseFloat(shipped.replace(',', '.'))).toLocaleString('it-IT')} box`"></span>
                                            </div>
                                            <div x-show="rowError" x-text="rowError" class="text-xs text-red-700"></div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <p class="card px-4 py-6 text-center text-sm text-stone-500">
                    TEA non ha ancora emesso il DDT per questo ordine: quando la merce parte compare qui (usa «Aggiorna stato da teaerp»).
                </p>
            @endforelse
        </section>

        <aside class="space-y-4 lg:col-start-2 lg:row-span-2 lg:row-start-1">
            <div class="card space-y-2 p-4 text-sm">
                <div class="flex justify-between"><span class="text-stone-500">Creato</span><span>{{ $order->created_at->format('d/m/Y H:i') }} · {{ $order->user?->name }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">Riferimento</span><span>{{ $order->reference }}</span></div>
                @if ($order->tea_increment_id)
                    <div class="flex justify-between"><span class="text-stone-500">Ordine in teaerp</span><span class="font-semibold">{{ $order->tea_increment_id }}</span></div>
                @endif
                @if ($order->total_cents !== null)
                    <div class="flex justify-between"><span class="text-stone-500">Imponibile</span><span class="tabular-nums">{{ euro($order->subtotal_cents) }}</span></div>
                    <div class="flex justify-between"><span class="text-stone-500">IVA</span><span class="tabular-nums">{{ euro($order->tax_cents) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>Totale</span><span class="tabular-nums">{{ euro($order->total_cents) }}</span></div>
                @endif
                @if ($order->status_checked_at)
                    <div class="text-xs text-stone-400">Stato letto da teaerp il {{ $order->status_checked_at->format('d/m/Y H:i') }}</div>
                @endif
                @if ($order->notes)
                    <p class="rounded-lg bg-stone-50 p-2 text-stone-700">{{ $order->notes }}</p>
                @endif
                @if ($order->error)
                    <p class="rounded-lg bg-red-50 p-2 text-red-700">{{ $order->error }}</p>
                @endif
            </div>
            <div class="card space-y-2 p-4">
                @if ($order->status === 'inviato')
                    <button class="btn-light w-full" :disabled="busy" @click="post('{{ route('tea-orders.refresh', $order) }}')">Aggiorna stato e DDT da teaerp</button>
                @else
                    <button class="btn-primary w-full" :disabled="busy" @click="post('{{ route('tea-orders.resend', $order) }}')">Riprova l'invio a TEA</button>
                @endif
                <p x-show="msg" x-text="msg" class="text-sm text-stone-600"></p>
            </div>
        </aside>
    </div>
</x-layout>
