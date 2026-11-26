{{-- Stato della cassa automatica (VNE tipo 20): nella pagina Chiusura o caricato dal tablet --}}
@if ($vneError)
    <p class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $vneError }}</p>
@else
    @php
        $alertColors = [0 => 'bg-brand-100 text-brand-700', 1 => 'bg-amber-100 text-amber-800', 2 => 'bg-red-100 text-red-700', 3 => 'bg-stone-100 text-stone-500'];
        $alertLabels = [0 => 'ok', 1 => 'basso', 2 => 'vuoto', 3 => 'non riciclato'];
        $recycler = $status['recycler'] ?? [];
        $hopper = $status['hopper'] ?? [];

        $notesForChange = (int) ($recycler['totalInRecycle'] ?? 0);
        $coinsForChange = (int) ($hopper['totalInRecycle'] ?? 0);
        $notesBox = (int) ($recycler['stacker'] ?? 0);
        $coinsBox = (int) ($hopper['stacker'] ?? 0);

        // Cassette di raccolta (da svuotare): pezzi per taglio, solo i tagli presenti
        $notesBoxDetail = collect($recycler['detailNotesStacker'] ?? [])
            ->mapWithKeys(fn ($qty, $value) => [(int) $value => (int) $qty])->filter()->sortKeys();
        $coinsBoxDetail = collect($hopper)
            ->filter(fn ($qty, $key) => preg_match('/^moneta_\d+_cashbox$/', $key))
            ->mapWithKeys(fn ($qty, $key) => [(int) preg_replace('/\D/', '', $key) => (int) $qty])->filter()->sortKeys();
    @endphp

    <div class="mb-4 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-stone-100 p-3">
            <div class="text-xs text-stone-500">In macchina</div>
            <div class="text-lg font-bold">{{ euro($notesForChange + $coinsForChange + $notesBox + $coinsBox) }}</div>
        </div>
        <div class="rounded-xl bg-stone-50 p-3">
            <div class="text-xs text-stone-500">Per il resto</div>
            <div class="text-lg font-bold">{{ euro($notesForChange + $coinsForChange) }}</div>
        </div>
        <div class="rounded-xl bg-amber-50 p-3">
            <div class="text-xs text-amber-800">Nelle cassette, da svuotare</div>
            <div class="text-lg font-bold text-amber-900">{{ euro($notesBox + $coinsBox) }}</div>
        </div>
    </div>

    @if ($detailPopup ?? false)
        {{-- Pagina Chiusura: in pagina solo il riepilogo, il dettaglio per taglio in un popup --}}
        <div x-data="{ detail: false }">
            <button type="button" class="btn-light py-2" @click="detail = true">Dettaglio per taglio…</button>
            <div x-show="detail" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/50 p-3 sm:p-4"
                 @click.self="detail = false" @keydown.escape.window="detail = false">
                <div class="card max-h-[90vh] w-full max-w-2xl overflow-y-auto p-5">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-bold">Contenuto della cassa</h3>
                        <button type="button" class="text-2xl leading-none text-stone-400 hover:text-stone-700" @click="detail = false" aria-label="Chiudi">&times;</button>
                    </div>
    @endif

    <h3 class="mb-2 text-sm font-semibold text-stone-500">Banconote per il resto · {{ euro($notesForChange) }}</h3>
    <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ($recycler as $key => $note)
            @if (str_starts_with($key, 'banconota_'))
                <div class="rounded-xl bg-stone-50 p-2 text-center">
                    <div class="font-bold">{{ euro($note['valore']) }}</div>
                    <div class="text-sm">{{ $note['quantita'] }} pz</div>
                    <span class="badge {{ $alertColors[$note['alert']] ?? '' }}">{{ $alertLabels[$note['alert']] ?? '' }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <h3 class="mb-2 text-sm font-semibold text-stone-500">Monete per il resto · {{ euro($coinsForChange) }}</h3>
    <div class="mb-4 grid grid-cols-3 gap-2 sm:grid-cols-5">
        @foreach ($hopper as $key => $qty)
            @if (preg_match('/^moneta_(\d+)$/', $key, $m))
                @php $alert = $hopper[$key . '_alert'] ?? 0; @endphp
                <div class="rounded-xl bg-stone-50 p-2 text-center">
                    <div class="font-bold">{{ euro((int) $m[1]) }}</div>
                    <div class="text-sm">{{ $qty }} pz</div>
                    <span class="badge {{ $alertColors[$alert] ?? '' }}">{{ $alertLabels[$alert] ?? '' }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <h3 class="mb-2 text-sm font-semibold text-amber-800">Cassetta banconote · {{ euro($notesBox) }}</h3>
    @if ($notesBoxDetail->isNotEmpty())
        <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($notesBoxDetail as $value => $qty)
                <div class="rounded-xl bg-amber-50 p-2 text-center">
                    <div class="font-bold">{{ euro($value) }}</div>
                    <div class="text-sm">{{ $qty }} pz · {{ euro($value * $qty) }}</div>
                </div>
            @endforeach
        </div>
    @else
        <p class="mb-4 text-sm text-stone-500">{{ $notesBox ? 'La cassa non comunica il dettaglio per taglio.' : 'Vuota.' }}</p>
    @endif

    <h3 class="mb-2 text-sm font-semibold text-amber-800">Cassetta monete · {{ euro($coinsBox) }}</h3>
    @if ($coinsBoxDetail->isNotEmpty())
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
            @foreach ($coinsBoxDetail as $value => $qty)
                <div class="rounded-xl bg-amber-50 p-2 text-center">
                    <div class="font-bold">{{ euro($value) }}</div>
                    <div class="text-sm">{{ $qty }} pz · {{ euro($value * $qty) }}</div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-stone-500">{{ $coinsBox ? 'La cassa non comunica il dettaglio per taglio.' : 'Vuota.' }}</p>
    @endif
    @if ($detailPopup ?? false)
                </div>
            </div>
        </div>
    @endif
@endif
