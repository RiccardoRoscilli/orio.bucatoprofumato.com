<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stampa badge · Orio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page { size: A4; margin: 12mm; }
        /* Formato carta di credito: si ritagliano e si plastificano */
        .badge-card { width: 85.6mm; height: 54mm; break-inside: avoid; }
    </style>
</head>
<body class="bg-stone-100 text-stone-900 antialiased print:bg-white">
    <div class="mx-auto max-w-4xl p-6 print:max-w-none print:p-0">
        <div class="mb-6 print:hidden">
            <a href="{{ route('badges.index') }}" class="text-sm font-semibold text-brand-700">← Badge</a>
            <h1 class="mt-2 text-xl font-bold">Stampa badge</h1>
            @if (session('status'))
                <p class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">{{ session('status') }}</p>
            @endif
            <p class="mt-2 text-sm text-stone-600">Stampa su carta normale al 100% (senza «adatta alla pagina»), ritaglia e plastifica.</p>
            <button type="button" class="btn-primary mt-4 py-2" onclick="window.print()">Stampa</button>
        </div>

        <div class="flex flex-wrap gap-[4mm]">
            @foreach ($badges as $badge)
                <div class="badge-card flex items-center gap-[5mm] overflow-hidden rounded-[3mm] bg-white p-[5mm] shadow ring-1 ring-stone-300 print:shadow-none">
                    <div x-data="badge(@js($badge->code))" class="h-[40mm] w-[40mm] shrink-0 [&>svg]:h-full [&>svg]:w-full"></div>
                    <div class="min-w-0">
                        <div class="text-[7pt] font-semibold uppercase tracking-wide text-stone-500">Le Essenze di Elda</div>
                        <div class="mt-[2mm] text-[16pt] font-bold leading-tight">Operatore<br>{{ $badge->number }}</div>
                        <div class="mt-[2mm] text-[7pt] text-stone-500">Badge presenze · Orio</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</body>
</html>
