@props(['sale'])
@php
    $colors = [
        'bozza' => 'bg-stone-100 text-stone-700',
        'in_pagamento' => 'bg-amber-100 text-amber-800',
        'pagata' => 'bg-sky-100 text-sky-800',
        'scontrinata' => 'bg-brand-100 text-brand-700',
        'fatturata' => 'bg-violet-100 text-violet-800',
        'annullata' => 'bg-red-100 text-red-700',
    ];
@endphp
<span class="badge {{ $colors[$sale->status] ?? 'bg-stone-100' }}">{{ $sale->statusLabel() }}</span>
