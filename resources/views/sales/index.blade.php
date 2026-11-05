<x-layout title="Vendite">
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <h1 class="mr-auto text-xl font-bold">Vendite</h1>
        <form class="flex flex-wrap items-end gap-2">
            <input type="date" name="day" value="{{ $day->format('Y-m-d') }}" class="input w-auto py-2">
            <select name="status" class="input w-auto py-2">
                <option value="">Tutti gli stati</option>
                @foreach (\App\Models\Sale::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn-light py-2">Filtra</button>
        </form>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full text-left text-sm">
            <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Ora</th>
                    <th class="px-4 py-3">Documento</th>
                    <th class="hidden px-4 py-3 md:table-cell">Pagamento</th>
                    <th class="hidden px-4 py-3 md:table-cell">Operatore</th>
                    <th class="px-4 py-3">Stato</th>
                    <th class="px-4 py-3 text-right">Totale</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($sales as $sale)
                    <tr class="cursor-pointer hover:bg-stone-50" onclick="location.href='{{ route('sales.show', $sale) }}'">
                        <td class="px-4 py-3 font-semibold">{{ $sale->id }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $sale->created_at->format('H:i') }}</td>
                        <td class="px-4 py-3">
                            @if ($sale->document_type === 'fattura')
                                Fattura {{ $sale->fattura24_doc_number }}
                                <div class="text-xs text-stone-500">{{ $sale->customer?->name }}</div>
                            @else
                                Scontrino {{ $sale->receipt_number }}
                            @endif
                        </td>
                        <td class="hidden px-4 py-3 md:table-cell">{{ \App\Models\Sale::PAYMENT_METHODS[$sale->payment_method] ?? '—' }}</td>
                        <td class="hidden px-4 py-3 md:table-cell">{{ $sale->user->name }}</td>
                        <td class="px-4 py-3"><x-status-badge :sale="$sale" /></td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ euro($sale->total_cents) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-stone-500">Nessuna vendita in questo giorno.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
