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

        <aside class="space-y-4">
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
                    <button class="btn-light w-full" :disabled="busy" @click="post('{{ route('tea-orders.refresh', $order) }}')">Aggiorna stato da teaerp</button>
                @else
                    <button class="btn-primary w-full" :disabled="busy" @click="post('{{ route('tea-orders.resend', $order) }}')">Riprova l'invio a TEA</button>
                @endif
                <p x-show="msg" x-text="msg" class="text-sm text-stone-600"></p>
            </div>
        </aside>
    </div>
</x-layout>
