<x-layout title="Chiusura">
    <div x-data="closure({ fiscalUrl: @js(route('closure.fiscal')), confirmUrl: @js(route('closure.fiscal.confirm')), vneUrl: @js(route('closure.vne', '__ID__')), pendingVneId: @js($pendingVne?->id), fiscalClosedToday: @js($fiscalClosedToday), summary: @js([['label' => 'Contanti (VNE)', 'value' => euro($totals['contanti'])], ['label' => 'Carta / Bancomat (POS)', 'value' => euro($totals['pos'])], ['label' => 'Scontrini e fatture', 'value' => $totals['scontrini'] . ' + ' . $totals['fatture']]]) })">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="mr-auto">
                <h1 class="text-xl font-bold">Chiusura giornata · {{ today()->format('d/m/Y') }}</h1>
                <p class="text-sm text-stone-500">
                    Incassi {{ $periodFrom ? 'dall\'ultima chiusura (' . $periodFrom->format('d/m/Y H:i') . ')' : 'dall\'inizio' }} a ora
                </p>
            </div>
            @if ($fiscalClosedToday)
                <span class="badge bg-brand-50 px-3 py-1.5 text-sm text-brand-700">Chiusura fiscale di oggi fatta</span>
            @endif
            <button type="button" class="btn-primary" @click="closeDay()" :disabled="step">
                <svg x-show="step" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                    <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                </svg>
                <span x-text="step === 'fiscal' ? 'Chiusura fiscale…' : step === 'vne' ? 'Chiusura cassa automatica…' : 'Chiudi giornata'"></span>
            </button>
        </div>

        <section class="card mb-4 p-4">
            <h2 class="mb-3 font-bold">Stato cassa automatica</h2>
            @if ($vneDeferred)
                {{-- Cassa collegata dal tablet: lo stato lo chiede il browser, sulla rete del negozio --}}
                <div x-data="{ html: null, error: null }"
                     x-init="axios.post(@js(route('closure.vne-status')), { popup: 1 }).then(r => html = r.data).catch(e => error = e.response?.data?.message ?? e.message)">
                    <p x-show="!html && !error" class="text-sm text-stone-500">Lettura dello stato dal tablet…</p>
                    <p x-show="error" x-cloak x-text="error" class="rounded-lg bg-red-50 p-3 text-sm text-red-700"></p>
                    <div x-html="html"></div>
                </div>
            @else
                @include('closure-vne-status', ['detailPopup' => true])
            @endif
        </section>

        <p class="mb-4 text-sm text-stone-600">
            Il pulsante fa la <strong>chiusura fiscale della stampante RT</strong> (obbligatoria entro le 24, anche senza incassi) e poi la <strong>chiusura della cassa automatica</strong>.
        </p>

        {{-- Chiusura automatica dal tablet abilitato --}}
        <div class="mb-4 rounded-xl bg-white px-4 py-3 text-sm ring-1 ring-stone-200">
            <div>
                <span class="font-semibold">Chiusura automatica alle {{ config('orio.closure.auto_at') }}</span>
                @if ($autoDevice)
                    dal tablet abilitato ({{ $autoDevice->name }}), se a quell'ora non è già stata fatta: il tablet deve restare acceso con Orio aperto.
                @else
                    <span class="text-amber-800">— nessun tablet abilitato: abilitalo da Gestione → Badge, altrimenti la chiusura automatica non parte.</span>
                @endif
            </div>
            @if ($autoLast)
                <div class="mt-1 {{ $autoLast['ok'] ? 'text-brand-800' : 'text-red-700' }}">
                    Ultima: {{ \Illuminate\Support\Carbon::parse($autoLast['at'])->format('d/m H:i') }} · {{ $autoLast['device'] }} · {{ $autoLast['message'] }}
                </div>
            @endif
        </div>

        <div x-show="error" x-cloak class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-red-50 px-4 py-3 text-red-700 ring-1 ring-red-200">
            <span class="mr-auto" x-text="error"></span>
            <button type="button" x-show="closureId" class="btn-light py-2" @click="closeVne()">Riprova chiusura cassa automatica</button>
        </div>

        @if ($pendingVne)
            <div x-show="!error" class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-amber-50 px-4 py-3 text-amber-900 ring-1 ring-amber-200">
                <span class="mr-auto">La chiusura fiscale del {{ $pendingVne->fiscal_closed_at->format('d/m H:i') }} è fatta, ma la cassa automatica non è stata chiusa{{ $pendingVne->vne_error ? ': ' . $pendingVne->vne_error : '.' }}</span>
                <button type="button" class="btn-light py-2" @click="closeVne()" :disabled="step">Chiudi cassa automatica</button>
            </div>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-4"><div class="text-sm text-stone-500">Contanti (VNE)</div><div class="text-2xl font-bold tabular-nums">{{ euro($totals['contanti']) }}</div></div>
        <div class="card p-4"><div class="text-sm text-stone-500">Carta / Bancomat (POS)</div><div class="text-2xl font-bold tabular-nums">{{ euro($totals['pos']) }}</div></div>
    </div>
    <div class="mt-3 flex flex-wrap gap-4 text-sm text-stone-600">
        <span>{{ $totals['scontrini'] }} scontrini</span>
        <span>{{ $totals['fatture'] }} fatture</span>
        @if ($totals['da_documentare'])
            <a href="{{ route('sales.index', ['status' => 'pagata']) }}" class="font-semibold text-amber-700">{{ $totals['da_documentare'] }} vendite pagate senza documento</a>
        @endif
    </div>

    <div class="mt-6">
        <section class="card p-4">
            <h2 class="mb-3 font-bold">Ultime chiusure</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-stone-500">
                    <tr><th class="py-2">Data</th><th class="py-2">Fiscale RT</th><th class="py-2 text-right">Incasso VNE</th><th class="py-2 text-right">Orio VNE</th><th class="py-2 text-right">Differenza</th></tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($closures as $closure)
                        @php
                            $orio = $closure->orio_cash_cents + $closure->orio_card_cents;
                            // Segnata come risolta senza chiudere la cassa automatica: niente confronto
                            $vneSkipped = (bool) ($closure->vne_data['skipped'] ?? false);
                            $vneCash = $closure->vne_closed_at && ! $vneSkipped ? (int) ($closure->vne_data['total_payments'] ?? 0) : null;
                        @endphp
                        <tr>
                            <td class="py-2">{{ $closure->created_at->format('d/m H:i') }}<div class="text-xs text-stone-500">{{ $closure->automatic ? 'Automatica · ' : '' }}{{ $closure->user->name }}{{ $closure->period_from ? ' · dal ' . $closure->period_from->format('d/m H:i') : '' }}</div></td>
                            <td class="py-2">
                                @if ($closure->fiscal_closed_at)
                                    <span class="badge bg-brand-50 text-brand-700">Z {{ $closure->fiscal_z_number }}</span>
                                @else
                                    <span class="badge bg-stone-100 text-stone-500">—</span>
                                @endif
                            </td>
                            <td class="py-2 text-right tabular-nums">{!! $vneSkipped ? '<span class="text-stone-500">non chiusa</span>' : ($vneCash === null ? '<span class="text-amber-700">da chiudere</span>' : euro($vneCash)) !!}</td>
                            <td class="py-2 text-right tabular-nums">{{ euro($orio) }}</td>
                            <td class="py-2 text-right font-semibold tabular-nums {{ $vneCash !== null && $vneCash - $orio ? 'text-red-600' : 'text-brand-700' }}">{{ $vneCash === null ? '' : euro($vneCash - $orio) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-stone-500">Nessuna chiusura registrata.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-layout>
