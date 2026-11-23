<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>{{ $title ?? 'Cassa' }} · Orio</title>
    @auth
        <meta name="orio-auth" content="1">
        {{-- Solo sul tablet abilitato: alle 23 fa da solo la chiusura della giornata --}}
        @if (\App\Models\AttendanceDevice::fromRequest(request()))
            <meta name="orio-auto-closure" content="{{ json_encode([
                'at' => config('orio.closure.auto_at'), 'until' => config('orio.closure.auto_until'),
                'startUrl' => route('closure.auto'), 'resultUrl' => route('closure.auto.result'),
                'fiscalUrl' => route('closure.fiscal'), 'confirmUrl' => route('closure.fiscal.confirm'), 'vneUrl' => route('closure.vne', '__ID__'),
            ]) }}">
        @endif
    @endauth
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
                    $nav = ['cassa' => 'Cassa', 'sales.index' => 'Vendite', 'products.index' => 'Prodotti', 'stock.index' => 'Magazzino', 'tea-orders.index' => 'Nuovo ordine TEA', 'tea-orders.sent' => 'Ordini inviati', 'customers.index' => 'Clienti', 'closure' => 'Chiusura'];
                    // Voci dell'admin raccolte in un menu, per non allargare la barra.
                    // La prova della stampante si apre dalla Configurazione; OpenAPI non ha voce (dati solo per la rifatturazione)
                    $adminNav = auth()->user()->can('admin')
                        ? ['attendances.index' => 'Presenze', 'badges.index' => 'Badge', 'users.index' => 'Utenti', 'settings' => 'Configurazione']
                        : [];
                    // Ordini TEA: due voci, il nuovo ordine e lo storico (con il dettaglio di ogni ordine)
                    $activeRoutes = ['tea-orders.index' => ['tea-orders.index'], 'tea-orders.sent' => ['tea-orders.sent', 'tea-orders.show']];
                    $isActive = fn ($route) => request()->routeIs(...($activeRoutes[$route] ?? [str_replace('.index', '.*', $route)]));
                    $adminActive = collect(array_keys($adminNav))->contains($isActive) || request()->routeIs('fiscal-printer');
                @endphp
                {{-- Su schermi stretti le voci scorrono di lato invece di allargare la pagina --}}
                <nav class="flex min-w-0 items-center gap-1 overflow-x-auto [scrollbar-width:none]">
                    @foreach ($nav as $route => $label)
                        <a href="{{ route($route) }}"
                           class="shrink-0 rounded-lg px-3 py-2 text-sm font-semibold {{ $isActive($route) ? 'bg-brand-50 text-brand-700' : 'text-stone-600 hover:bg-stone-100' }}">{{ $label }}</a>
                    @endforeach
                </nav>
                @if ($adminNav)
                    <div class="relative shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false">
                        <button type="button" @click="open = !open" :aria-expanded="open"
                                class="flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-semibold {{ $adminActive ? 'bg-brand-50 text-brand-700' : 'text-stone-600 hover:bg-stone-100' }}">
                            Gestione
                            <svg class="h-4 w-4 transition" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition.origin.top.left @click.outside="open = false"
                             class="absolute left-0 top-full z-30 mt-1 w-48 rounded-xl bg-white p-1 shadow-xl ring-1 ring-stone-200">
                            @foreach ($adminNav as $route => $label)
                                <a href="{{ route($route) }}"
                                   class="block rounded-lg px-3 py-2 text-sm font-semibold {{ $isActive($route) ? 'bg-brand-50 text-brand-700' : 'text-stone-700 hover:bg-stone-100' }}">{{ $label }}</a>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="ml-auto flex shrink-0 items-center gap-3 text-sm">
                    @if (config('orio.vne.driver') === 'fake')
                        <span class="badge hidden whitespace-nowrap bg-amber-100 text-amber-800 xl:inline-flex">VNE simulata</span>
                    @endif
                    @if (config('orio.fiscal_printer.driver') === 'fake')
                        <span class="badge hidden whitespace-nowrap bg-amber-100 text-amber-800 xl:inline-flex">Stampante simulata</span>
                    @endif
                    <x-support-button />
                    <span class="hidden text-stone-500 lg:inline">{{ auth()->user()->name }}</span>
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
                <div data-closure-reminder class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-red-600 px-4 py-3 font-semibold text-white">
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
