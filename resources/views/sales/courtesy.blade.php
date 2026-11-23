<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Scontrino di cortesia {{ $sale->receipt_number }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-stone-100 py-4">
    {{-- Anteprima indicativa del documento non fiscale stampato dalla RT --}}
    <div class="mx-auto w-[calc(48ch+2.5rem)] bg-white px-5 py-6 font-mono text-[12px] leading-snug text-stone-900 shadow">
        <div class="mb-2 text-center">
            <div class="font-bold">{{ config('orio.company.name') }}</div>
            @if (config('orio.company.address'))<div>{{ config('orio.company.address') }}</div>@endif
        </div>
        @foreach ($lines as $line)
            @if (str_starts_with($line['text'], '---'))
                <div class="my-2 border-t border-dashed border-stone-400"></div>
            @else
                <div @class([
                    'min-h-[1em] break-words',
                    'text-center text-[15px] font-bold' => $line['style'] === 'title',
                    'text-center' => $loop->index < 5 && $line['style'] !== 'title',
                    'font-bold' => $line['style'] === 'bold',
                ])>{{ $line['text'] }}</div>
            @endif
        @endforeach
    </div>
</body>
</html>
