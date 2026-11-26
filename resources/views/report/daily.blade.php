<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Rapporto · Orio</title>
    {{-- PWA: pagina e manifest solo per gli admin, l'installazione dalla Home parte da qui --}}
    <link rel="manifest" href="{{ route('report.manifest') }}">
    <meta name="theme-color" content="#1f7169">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Orio Rapporto">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="{{ route('report.icon', 180) }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased" style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)">
    <main class="mx-auto max-w-md px-4 py-4">
        <div class="mb-4 flex items-center justify-between">
            <img src="{{ asset('images/logo-elda.png') }}" alt="Le Essenze di Elda" class="h-7 w-auto">
            <a href="{{ route('report.daily', ['giorno' => $day->toDateString()]) }}" class="text-sm font-semibold text-brand-700">Aggiorna</a>
        </div>

        <div class="mb-4 flex items-center justify-between gap-2">
            <a href="{{ route('report.daily', ['giorno' => $prev->toDateString()]) }}" class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-2xl ring-1 ring-stone-200" aria-label="Giorno prima">‹</a>
            <div class="text-center">
                <div class="text-lg font-bold capitalize">{{ $day->isToday() ? 'Oggi' : ($day->isYesterday() ? 'Ieri' : $day->translatedFormat('l')) }}</div>
                <div class="text-sm text-stone-500">{{ $day->translatedFormat('j F Y') }}</div>
            </div>
            @if ($next)
                <a href="{{ route('report.daily', ['giorno' => $next->toDateString()]) }}" class="flex h-11 w-11 items-center justify-center rounded-full bg-white text-2xl ring-1 ring-stone-200" aria-label="Giorno dopo">›</a>
            @else
                <span class="h-11 w-11"></span>
            @endif
        </div>

        <section class="mb-3 rounded-2xl bg-brand-600 p-5 text-white">
            <div class="text-sm opacity-80">Incassato</div>
            <div class="text-4xl font-bold tabular-nums">{{ euro($total) }}</div>
            <div class="mt-1 text-sm opacity-80">{{ $count }} {{ $count === 1 ? 'vendita' : 'vendite' }} · {{ $scontrini }} scontrini · {{ $fatture }} fatture</div>
        </section>

        <div class="mb-3 grid grid-cols-2 gap-3">
            <div class="rounded-2xl bg-white p-4 ring-1 ring-stone-200">
                <div class="text-sm text-stone-500">Contanti (cassa)</div>
                <div class="text-xl font-bold tabular-nums">{{ euro($contanti) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-4 ring-1 ring-stone-200">
                <div class="text-sm text-stone-500">Carta (banca)</div>
                <div class="text-xl font-bold tabular-nums">{{ euro($pos) }}</div>
            </div>
        </div>

        @if ($daDocumentare || $voidedCount)
            <div class="mb-3 space-y-1 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200">
                @if ($daDocumentare)
                    <div>{{ $daDocumentare }} {{ $daDocumentare === 1 ? 'vendita pagata' : 'vendite pagate' }} senza scontrino o fattura</div>
                @endif
                @if ($voidedCount)
                    <div>{{ $voidedCount }} {{ $voidedCount === 1 ? 'vendita annullata' : 'vendite annullate' }} ({{ euro($voidedTotal) }}), {{ $voidedCount === 1 ? 'non conteggiata' : 'non conteggiate' }}</div>
                @endif
            </div>
        @endif

        <section class="rounded-2xl bg-white p-4 ring-1 ring-stone-200">
            <h2 class="mb-2 font-bold">Venduto per operatore</h2>
            @forelse ($operators as $op)
                <div class="flex items-center justify-between gap-3 border-t border-stone-100 py-3 first-of-type:border-0">
                    <div class="min-w-0">
                        <div class="truncate font-semibold">{{ $op['name'] }}</div>
                        <div class="text-xs text-stone-500"><span class="whitespace-nowrap">{{ $op['count'] }} {{ $op['count'] === 1 ? 'vendita' : 'vendite' }}</span> · <span class="whitespace-nowrap">contanti {{ euro($op['contanti']) }}</span> · <span class="whitespace-nowrap">carta {{ euro($op['pos']) }}</span></div>
                    </div>
                    <div class="shrink-0 text-lg font-bold tabular-nums">{{ euro($op['total']) }}</div>
                </div>
            @empty
                <p class="py-3 text-sm text-stone-500">Nessuna vendita in questo giorno.</p>
            @endforelse
        </section>
    </main>
</body>
</html>
