<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Scontrino {{ $sale->receipt_number }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-stone-100 py-4">
    {{-- Anteprima indicativa: il documento commerciale vero lo compone la stampante RT --}}
    <div class="mx-auto w-[300px] bg-white px-5 py-6 font-mono text-[12px] leading-snug text-stone-900 shadow">
        <div class="text-center">
            <div class="font-bold">{{ config('orio.company.name') }}</div>
            @if (config('orio.company.address'))<div>{{ config('orio.company.address') }}</div>@endif
            @if (config('orio.company.vat_code'))<div>P.IVA {{ config('orio.company.vat_code') }}</div>@endif
            <div class="mt-2 font-bold">DOCUMENTO COMMERCIALE</div>
            <div>di vendita o prestazione</div>
        </div>
        <div class="my-2 border-t border-dashed border-stone-400"></div>
        <div class="flex justify-between text-[11px]"><span>DESCRIZIONE</span><span>IVA&nbsp;&nbsp;Prezzo(€)</span></div>
        @foreach ($sale->items as $item)
            <div class="flex justify-between gap-2">
                <span class="truncate">{{ $item->qty > 1 ? rtrim(rtrim(number_format($item->qty, 3, ',', ''), '0'), ',') . 'x ' : '' }}{{ mb_strtoupper($item->description) }}</span>
                <span class="shrink-0">{{ $item->vat_rate }}%&nbsp;&nbsp;{{ number_format($item->lineTotalCents() / 100, 2, ',', '.') }}</span>
            </div>
        @endforeach
        <div class="my-2 border-t border-dashed border-stone-400"></div>
        <div class="flex justify-between text-[15px] font-bold"><span>TOTALE COMPLESSIVO</span><span>{{ number_format($sale->total_cents / 100, 2, ',', '.') }}</span></div>
        @php
            $vatTotal = $sale->items->sum(fn ($i) => $i->lineTotalCents() - $i->lineTotalCents() / (1 + $i->vat_rate / 100));
        @endphp
        <div class="flex justify-between"><span>di cui IVA</span><span>{{ number_format($vatTotal / 100, 2, ',', '.') }}</span></div>
        @if (in_array($sale->payment_method, ['carta', 'pos'], true))
            <div class="flex justify-between"><span>Pagamento elettronico</span><span>{{ number_format($sale->paid_cents / 100, 2, ',', '.') }}</span></div>
        @else
            <div class="flex justify-between"><span>Pagamento contante</span><span>{{ number_format(($vne['inserted'] ?? $sale->paid_cents) / 100, 2, ',', '.') }}</span></div>
            <div class="flex justify-between"><span>Resto</span><span>{{ number_format(($vne['rest'] ?? 0) / 100, 2, ',', '.') }}</span></div>
        @endif
        <div class="my-2 border-t border-dashed border-stone-400"></div>
        <div class="text-center">
            <div>{{ $sale->closed_at?->format('d-m-Y H:i') }}</div>
            <div>DOCUMENTO N. {{ $sale->receipt_number }}</div>
            <div class="mt-1 text-[10px] text-stone-500">Anteprima · stampante simulata</div>
        </div>
    </div>
</body>
</html>
