<x-layout title="Chiusura">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Chiusura giornata · {{ today()->format('d/m/Y') }}</h1>
        <form method="POST" action="{{ route('closure.store') }}" onsubmit="return confirm('Eseguire la chiusura cassa sulla VNE? I totali della macchina ripartono da zero.')">
            @csrf
            <button class="btn-primary">Esegui chiusura cassa</button>
        </form>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><div class="text-sm text-stone-500">Contanti (VNE)</div><div class="text-2xl font-bold tabular-nums">{{ euro($totals['contanti']) }}</div></div>
        <div class="card p-4"><div class="text-sm text-stone-500">Carta (VNE)</div><div class="text-2xl font-bold tabular-nums">{{ euro($totals['carta']) }}</div></div>
        <div class="card p-4"><div class="text-sm text-stone-500">POS esterno</div><div class="text-2xl font-bold tabular-nums">{{ euro($totals['pos']) }}</div></div>
    </div>
    <div class="mt-3 flex flex-wrap gap-4 text-sm text-stone-600">
        <span>{{ $totals['scontrini'] }} scontrini</span>
        <span>{{ $totals['fatture'] }} fatture</span>
        @if ($totals['da_documentare'])
            <a href="{{ route('sales.index', ['status' => 'pagata']) }}" class="font-semibold text-amber-700">{{ $totals['da_documentare'] }} vendite pagate senza documento</a>
        @endif
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <section class="card p-4">
            <h2 class="mb-3 font-bold">Stato cassa automatica</h2>
            @if ($vneError)
                <p class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $vneError }}</p>
            @else
                @php
                    $alertColors = [0 => 'bg-brand-100 text-brand-700', 1 => 'bg-amber-100 text-amber-800', 2 => 'bg-red-100 text-red-700', 3 => 'bg-stone-100 text-stone-500'];
                    $alertLabels = [0 => 'ok', 1 => 'basso', 2 => 'vuoto', 3 => 'non riciclato'];
                @endphp
                <h3 class="mb-2 text-sm font-semibold text-stone-500">Banconote · {{ euro($status['recycler']['totalInRecycle'] ?? 0) }} in riciclo, {{ euro($status['recycler']['stacker'] ?? 0) }} nello stacker</h3>
                <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($status['recycler'] ?? [] as $key => $note)
                        @if (str_starts_with($key, 'banconota_'))
                            <div class="rounded-xl bg-stone-50 p-2 text-center">
                                <div class="font-bold">{{ euro($note['valore']) }}</div>
                                <div class="text-sm">{{ $note['quantita'] }} pz</div>
                                <span class="badge {{ $alertColors[$note['alert']] ?? '' }}">{{ $alertLabels[$note['alert']] ?? '' }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
                <h3 class="mb-2 text-sm font-semibold text-stone-500">Monete · {{ euro($status['hopper']['totalInRecycle'] ?? 0) }} in riciclo</h3>
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach ($status['hopper'] ?? [] as $key => $qty)
                        @if (preg_match('/^moneta_(\d+)$/', $key, $m))
                            @php $alert = $status['hopper'][$key . '_alert'] ?? 0; @endphp
                            <div class="rounded-xl bg-stone-50 p-2 text-center">
                                <div class="font-bold">{{ euro((int) $m[1]) }}</div>
                                <div class="text-sm">{{ $qty }} pz</div>
                                <span class="badge {{ $alertColors[$alert] ?? '' }}">{{ $alertLabels[$alert] ?? '' }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        <section class="card p-4">
            <h2 class="mb-3 font-bold">Ultime chiusure</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-stone-500">
                    <tr><th class="py-2">Data</th><th class="py-2 text-right">Incasso VNE</th><th class="py-2 text-right">Orio contanti</th><th class="py-2 text-right">Differenza</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($closures as $closure)
                        @php
                            $vneCash = (int) ($closure->vne_data['total_payments'] ?? 0);
                            $diff = $vneCash - $closure->orio_cash_cents - $closure->orio_card_cents;
                        @endphp
                        <tr>
                            <td class="py-2">{{ $closure->created_at->format('d/m H:i') }}<div class="text-xs text-stone-500">{{ $closure->user->name }}</div></td>
                            <td class="py-2 text-right tabular-nums">{{ euro($vneCash) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ euro($closure->orio_cash_cents + $closure->orio_card_cents) }}</td>
                            <td class="py-2 text-right font-semibold tabular-nums {{ $diff ? 'text-red-600' : 'text-brand-700' }}">{{ euro($diff) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-stone-500">Nessuna chiusura registrata.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-layout>
