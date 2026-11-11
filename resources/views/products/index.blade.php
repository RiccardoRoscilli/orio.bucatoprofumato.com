<x-layout title="Prodotti">
    @vite('resources/js/products.js')

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Prodotti</h1>
        <span class="text-sm text-stone-500">
            Ultima sincronizzazione: <span id="last-sync">{{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d/m/Y H:i') : 'mai' }}</span>
        </span>
        @can('admin')
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
            $filters = ['attivi' => 'Attivi', 'prezzi' => 'Prezzi da rivedere', 'sottozero' => 'Giacenza sotto zero', 'disattivi' => 'Non attivi sul sito', 'tutti' => 'Tutti'];
        @endphp
        @foreach ($filters as $key => $label)
            <button type="button" data-filter="{{ $key }}" class="filter-chip {{ $filter === $key ? 'is-active' : '' }}">
                {{ $label }}
                @if ($key === 'prezzi')
                    <span id="review-count" class="{{ $toReview ? '' : 'hidden' }} ml-1 rounded-full bg-amber-400 px-1.5 text-xs text-amber-950">{{ $toReview }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div id="review-banner" class="{{ $filter === 'prezzi' && $toReview ? '' : 'hidden' }} mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <span class="mr-auto">Questi prodotti hanno in cassa un prezzo diverso da quello attuale su bucatoprofumato.com. Correggi il prezzo o allinealo al sito.</span>
        @can('admin')
            <form method="POST" action="{{ route('products.align-all') }}" data-confirm="Allineare al sito tutti i {{ $toReview }} prezzi da rivedere?" data-confirm-label="Allinea prezzi">
                @csrf
                <button class="btn-primary py-2 text-sm">Allinea tutti al sito</button>
            </form>
        @endcan
    </div>

    <div class="card p-4">
        <table id="products-table" class="products-table w-full text-sm"
               data-data-url="{{ route('products.data') }}"
               data-show-url="{{ route('products.show', '__ID__') }}"
               data-price-url="{{ route('products.price', '__ID__') }}"
               data-align-url="{{ route('products.align', '__ID__') }}"
               data-is-admin="{{ auth()->user()->can('admin') ? '1' : '0' }}"
               data-initial-filter="{{ $filter }}">
            <thead>
                <tr>
                    <th></th>
                    <th>Prodotto</th>
                    <th>SKU</th>
                    <th>EAN</th>
                    <th class="dt-right">In negozio</th>
                    <th class="dt-right">Prezzo sito</th>
                    <th class="dt-right">Prezzo cassa</th>
                    <th></th>
                </tr>
            </thead>
        </table>
    </div>
</x-layout>
