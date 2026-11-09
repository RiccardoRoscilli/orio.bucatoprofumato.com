<x-layout :title="'Vendita #' . $sale->id">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <a href="{{ route('sales.index', ['day' => $sale->created_at->format('Y-m-d')]) }}" class="text-sm font-semibold text-brand-700">← Vendite</a>
        <h1 class="text-xl font-bold">Vendita #{{ $sale->id }}</h1>
        <x-status-badge :sale="$sale" />
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_360px]">
        <section class="space-y-5">
            <div class="card overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                        <tr><th class="px-4 py-3">Descrizione</th><th class="px-4 py-3 text-right">Q.tà</th><th class="px-4 py-3 text-right">Prezzo</th><th class="px-4 py-3 text-right">IVA</th><th class="px-4 py-3 text-right">Totale</th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($sale->items as $item)
                            <tr>
                                <td class="px-4 py-3">{{ $item->description }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ rtrim(rtrim(number_format($item->qty, 3, ',', ''), '0'), ',') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ euro($item->unit_price_cents) }}</td>
                                <td class="px-4 py-3 text-right">{{ $item->vat_rate }}%</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ euro($item->lineTotalCents()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-stone-200"><td colspan="4" class="px-4 py-3 text-right font-semibold">Totale</td><td class="px-4 py-3 text-right text-lg font-bold tabular-nums">{{ euro($sale->total_cents) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            @if ($sale->vneTransactions->isNotEmpty())
                <div class="card p-4">
                    <h2 class="mb-3 font-bold">Log cassa VNE</h2>
                    <div class="space-y-2 text-xs">
                        @foreach ($sale->vneTransactions as $t)
                            <details class="rounded-lg bg-stone-50 p-2">
                                <summary class="cursor-pointer font-semibold">
                                    {{ $t->created_at->format('H:i:s') }} · tipo {{ $t->tipo }}
                                    @if ($t->error) <span class="text-red-600">· {{ $t->error }}</span> @endif
                                    @if ($t->tipo === 2) · {{ $t->response['payment_details']['status'] ?? '' }} @endif
                                </summary>
                                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap">→ {{ json_encode($t->request) }}
← {{ json_encode($t->response) }}</pre>
                            </details>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($sale->fattura24_xml)
                <details class="card p-4">
                    <summary class="cursor-pointer font-bold">XML Fattura24</summary>
                    <pre class="mt-3 overflow-x-auto text-xs">{{ $sale->fattura24_xml }}</pre>
                </details>
            @endif
            @if ($receiptXml && $sale->document_type === 'scontrino')
                <details class="card p-4">
                    <summary class="cursor-pointer font-bold">Comandi stampante fiscale</summary>
                    <pre class="mt-3 overflow-x-auto text-xs">{{ $receiptXml }}</pre>
                </details>
            @endif
        </section>

        <aside class="space-y-4">
            <div class="card space-y-2 p-4 text-sm">
                <div class="flex justify-between"><span class="text-stone-500">Data</span><span>{{ $sale->created_at->format('d/m/Y H:i') }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">Operatore</span><span>{{ $sale->user->name }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">Pagamento</span><span>{{ \App\Models\Sale::PAYMENT_METHODS[$sale->payment_method] ?? '—' }}</span></div>
                <div class="flex justify-between"><span class="text-stone-500">Incassato</span><span class="font-semibold">{{ euro($sale->paid_cents) }}</span></div>
                @if ($sale->receipt_number)
                    <div class="flex justify-between"><span class="text-stone-500">Scontrino</span><span class="font-semibold">{{ $sale->receipt_number }}</span></div>
                @endif
                @if ($sale->fattura24_doc_number)
                    <div class="flex justify-between"><span class="text-stone-500">Fattura</span><span class="font-semibold">{{ $sale->fattura24_doc_number }}</span></div>
                @endif
                @if ($sale->notes)
                    <p class="rounded-lg bg-amber-50 p-2 text-amber-800">{{ $sale->notes }}</p>
                @endif
            </div>

            @if ($sale->customer)
                <div class="card p-4 text-sm">
                    <div class="font-semibold">{{ $sale->customer->name }}</div>
                    <div class="text-stone-600">P.IVA {{ $sale->customer->vat_code }}</div>
                    <div class="text-stone-600">{{ $sale->customer->address }}, {{ $sale->customer->postcode }} {{ $sale->customer->city }} {{ $sale->customer->province }}</div>
                </div>
            @endif

            <div class="card space-y-2 p-4" x-data="{ busy: false, msg: null, async emit(url) { this.busy = true; this.msg = null; try { const { data } = await axios.post(url); this.msg = data.message ?? null; if (data.success !== false) location.reload(); } catch (e) { this.msg = e.response?.data?.message ?? 'Errore'; } this.busy = false; } }">
                @if ($sale->status === 'scontrinata')
                    <a href="{{ route('sales.receipt', $sale) }}" target="_blank" class="btn-light w-full">Anteprima scontrino</a>
                @endif
                @if ($sale->status === 'fatturata' && $sale->fattura24_doc_id)
                    <a href="{{ route('sales.invoice.pdf', $sale) }}" target="_blank" class="btn-light w-full">PDF fattura</a>
                @endif
                @if ($sale->status === 'pagata')
                    @if ($sale->document_type === 'fattura')
                        <button class="btn-primary w-full" :disabled="busy" @click="emit('{{ route('sales.invoice', $sale) }}')">Invia fattura a Fattura24</button>
                    @else
                        <button class="btn-primary w-full" :disabled="busy" @click="busy = true; msg = null; emitReceipt({{ $sale->id }}).then(() => location.reload()).catch((e) => { msg = e.response?.data?.message ?? e.message; busy = false; })">Emetti scontrino</button>
                    @endif
                @endif
                @if (in_array($sale->status, ['bozza', 'pagata'], true))
                    <form method="POST" action="{{ route('sales.cancel', $sale) }}" onsubmit="return confirm('Annullare la vendita?')">
                        @csrf
                        <button class="btn-light w-full text-red-700">Annulla vendita</button>
                    </form>
                @endif
                <p x-show="msg" x-text="msg" class="text-sm text-amber-700"></p>
            </div>
        </aside>
    </div>
</x-layout>
