<x-layout title="Prodotti">
    @vite('resources/js/products.js')

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Prodotti</h1>
        <span class="text-sm text-stone-500">
            Ultima sincronizzazione: <span id="last-sync">{{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d/m/Y H:i') : 'mai' }}</span>
        </span>
        @can('admin')
            <a href="{{ route('products.create') }}" class="btn-light py-2">+ Nuovo prodotto</a>
            <button type="button" id="sync-button" data-url="{{ route('products.sync') }}" class="btn-light py-2">
                <svg data-spinner class="hidden h-4 w-4 animate-spin text-brand-600" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                <span data-label>Sincronizza da bucatoprofumato.com</span>
            </button>
        @endcan
    </div>

    <div id="sync-result" class="mb-4 hidden rounded-xl px-4 py-3 text-sm ring-1"></div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @php
            $filters = ['attivi' => 'Attivi', 'negozio' => 'In vendita in negozio', 'sottozero' => 'Giacenza sotto zero', 'promo' => 'In promo'];
        @endphp
        @foreach ($filters as $key => $label)
            <button type="button" data-filter="{{ $key }}" class="filter-chip {{ $filter === $key ? 'is-active' : '' }}">
                {{ $label }}
                @if ($key === 'promo' && $inPromo)
                    <span class="ml-1 rounded-full bg-brand-100 px-1.5 text-xs text-brand-800">{{ $inPromo }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div class="card p-4">
        <table id="products-table" class="products-table w-full text-sm"
               data-data-url="{{ route('products.data') }}"
               data-show-url="{{ route('products.show', '__ID__') }}"
               data-price-url="{{ route('products.price', '__ID__') }}"
               data-shop-url="{{ route('products.shop', '__ID__') }}"
               data-align-url="{{ route('products.align', '__ID__') }}"
               data-is-admin="{{ auth()->user()->can('admin') ? '1' : '0' }}"
               data-initial-filter="{{ $filter }}">
            <thead>
                <tr>
                    <th></th>
                    <th>Prodotto</th>
                    <th>SKU</th>
                    <th>EAN</th>
                    <th class="dt-center">In vendita</th>
                    <th class="dt-right">Giacenza</th>
                    <th class="dt-right">Prezzo sito</th>
                    <th class="dt-right">Prezzo Orio</th>
                    <th class="dt-right">Prezzo promo</th>
                    <th></th>
                </tr>
            </thead>
        </table>
    </div>
</x-layout>
