{{-- Stato della cassa automatica (VNE tipo 20): nella pagina Chiusura o caricato dal tablet --}}
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
