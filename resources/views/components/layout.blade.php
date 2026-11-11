<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>{{ $title ?? 'Cassa' }} · Orio</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
    @auth
        <header class="sticky top-0 z-20 border-b border-stone-200 bg-white/90 backdrop-blur">
            <div class="mx-auto flex max-w-7xl items-center gap-2 px-4 py-2">
                <a href="{{ route('cassa') }}" class="mr-2 flex shrink-0 items-center gap-2 md:mr-4">
                    <img src="{{ asset('images/logo-elda.png') }}" alt="Le Essenze di Elda" class="h-8 w-auto">
                </a>
                @php
                    $nav = ['cassa' => 'Cassa', 'sales.index' => 'Vendite', 'products.index' => 'Prodotti', 'stock.index' => 'Magazzino', 'customers.index' => 'Clienti', 'closure' => 'Chiusura'];
                    if (auth()->user()->can('admin')) {
                        $nav['users.index'] = 'Utenti';
                        $nav['fiscal-printer'] = 'Stampante';
                        $nav['openapi-calls'] = 'OpenAPI';
                    }
                @endphp
                @foreach ($nav as $route => $label)
                    <a href="{{ route($route) }}"
                       class="rounded-lg px-3 py-2 text-sm font-semibold {{ request()->routeIs(str_replace('.index', '.*', $route)) ? 'bg-brand-50 text-brand-700' : 'text-stone-600 hover:bg-stone-100' }}">{{ $label }}</a>
                @endforeach
                <div class="ml-auto flex items-center gap-3 text-sm">
                    @if (config('orio.vne.driver') === 'fake')
                        <span class="badge hidden whitespace-nowrap bg-amber-100 text-amber-800 xl:inline-flex">VNE simulata</span>
                    @endif
                    @if (config('orio.fiscal_printer.driver') === 'fake')
                        <span class="badge hidden whitespace-nowrap bg-amber-100 text-amber-800 xl:inline-flex">Stampante simulata</span>
                    @endif
                    <span class="hidden text-stone-500 sm:inline">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="rounded-lg px-3 py-2 font-semibold text-stone-600 hover:bg-stone-100">Esci</button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main class="mx-auto max-w-7xl px-4 py-5">
        @auth
            @php
                $closureStatus = app(\App\Services\Closure\ClosureStatus::class);
                $overdueDay = $closureStatus->overdueDay();
            @endphp
            @if ($overdueDay || $closureStatus->reminderDue())
                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-red-600 px-4 py-3 font-semibold text-white">
                    <span class="mr-auto">
                        @if ($overdueDay)
                            Manca la chiusura fiscale del {{ $overdueDay->format('d/m/Y') }}: gli scontrini sono bloccati finché non chiudi la giornata.
                        @else
                            Ricordati la chiusura giornata: la chiusura fiscale va fatta entro mezzanotte.
                        @endif
                    </span>
                    @unless (request()->routeIs('closure'))
                        <a href="{{ route('closure') }}" class="rounded-lg bg-white px-4 py-2 text-red-700">Chiudi giornata</a>
                    @endunless
                </div>
            @endif
        @endauth
        @if (session('status'))
            <div class="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-brand-700 ring-1 ring-brand-100">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-red-700 ring-1 ring-red-200">{{ session('error') }}</div>
        @endif
        {{ $slot }}
    </main>

    @auth
        <x-customer-modal />
    @endauth
    <x-confirm-dialog />
</body>
</html>
