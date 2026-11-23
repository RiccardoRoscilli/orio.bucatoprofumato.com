<x-layout title="Ordini inviati a TEA">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Ordini inviati a TEA</h1>
        <a href="{{ route('tea-orders.index') }}" class="btn-light py-2">Nuovo ordine</a>
    </div>

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
                    <tr><td colspan="6" class="px-4 py-6 text-center text-stone-400">Nessun ordine inviato.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $orders->links() }}</div>
</x-layout>
