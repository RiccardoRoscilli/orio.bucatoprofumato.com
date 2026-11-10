<x-layout title="Chiamate OpenAPI">
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="mr-auto">
            <h1 class="text-xl font-bold">Chiamate OpenAPI</h1>
            <p class="text-sm text-stone-500">Ricerche dei dati aziendali da partita IVA fatte da Orio. L'account OpenAPI è condiviso con emmycasa: qui ci sono solo le chiamate di Orio, da rifatturare.</p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <label class="text-sm">
                <span class="mb-1 block text-stone-500">Mese</span>
                <input type="month" name="month" value="{{ $month }}" class="input">
            </label>
            <button class="btn-primary py-2">Mostra</button>
            <a href="{{ route('openapi-calls.csv', ['month' => $month]) }}" class="btn-light whitespace-nowrap py-2">Scarica CSV</a>
        </form>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="card p-4">
            <div class="text-sm text-stone-500">Chiamate addebitate</div>
            <div class="text-2xl font-bold" data-charged>{{ $charged }}</div>
            <div class="text-xs text-stone-500">su {{ $calls->count() }} chiamate nel mese</div>
        </div>
        <div class="card p-4">
            <div class="text-sm text-stone-500">Prezzo per chiamata</div>
            <div class="text-2xl font-bold">€ {{ number_format($priceCents / 100, 2, ',', '.') }}</div>
            @if (! $priceCents)
                <div class="text-xs text-red-600">Imposta OPENAPI_PRICE_CENTS nel .env</div>
            @else
                <div class="text-xs text-stone-500">IVA esclusa</div>
            @endif
        </div>
        <div class="card p-4">
            <div class="text-sm text-stone-500">Da fatturare</div>
            <div class="text-2xl font-bold">€ {{ number_format($totalCents / 100, 2, ',', '.') }}</div>
            <div class="text-xs text-stone-500">IVA esclusa</div>
        </div>
    </div>

    @if ($months->isNotEmpty())
        <h2 class="mb-2 font-bold">Ultimi 12 mesi</h2>
        <div class="card mb-6 inline-block overflow-x-auto">
            <table class="text-sm">
                <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr><th class="px-4 py-2">Mese</th><th class="px-4 py-2 text-right">Addebitate</th><th class="px-4 py-2 text-right">Importo</th></tr>
                </thead>
                <tbody>
                    @foreach ($months as $m => $total)
                        <tr class="border-b border-stone-100 last:border-0">
                            <td class="px-4 py-2"><a class="underline" href="{{ route('openapi-calls', ['month' => $m]) }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m . '-01')->translatedFormat('F Y') }}</a></td>
                            <td class="px-4 py-2 text-right">{{ $total }}</td>
                            <td class="px-4 py-2 text-right">€ {{ number_format($total * $priceCents / 100, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="mb-2 font-bold">Dettaglio del mese</h2>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-4 py-3">Data</th><th class="px-4 py-3">Utente</th><th class="px-4 py-3">Partita IVA</th><th class="px-4 py-3">Esito</th><th class="px-4 py-3">Addebitata</th></tr>
            </thead>
            <tbody>
                @forelse ($calls as $call)
                    <tr class="border-b border-stone-100 last:border-0">
                        <td class="px-4 py-2">{{ $call->created_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $call->user?->name }}</td>
                        <td class="px-4 py-2 font-mono">{{ $call->vat_code }}</td>
                        <td class="px-4 py-2">
                            {{ $call->http_status ?? 'errore di rete' }}
                            @unless ($call->live) <span class="badge bg-stone-100 text-stone-600">sandbox</span> @endunless
                        </td>
                        <td class="px-4 py-2">{{ $call->charged ? 'sì' : 'no' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-stone-500">Nessuna chiamata in questo mese.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
