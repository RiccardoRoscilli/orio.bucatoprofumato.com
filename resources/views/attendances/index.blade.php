<x-layout title="Presenze">
    @php
        $hours = fn (int $minutes) => \App\Http\Controllers\AttendanceController::hours($minutes);
        $empty = ['id' => null, 'user_id' => '', 'started_at' => now()->format('Y-m-d\TH:i'), 'ended_at' => '', 'note' => ''];
        $reopen = $errors->any() ? [
            'id' => old('attendance_id') ? (int) old('attendance_id') : null,
            'user_id' => old('user_id', ''),
            'started_at' => old('started_at', ''),
            'ended_at' => old('ended_at', ''),
            'note' => old('note', ''),
        ] : null;
        $prev = $month->copy()->subMonth()->format('Y-m');
        $next = $month->copy()->addMonth()->format('Y-m');
    @endphp

    <div x-data="{ open: @js((bool) $reopen), form: @js($reopen ?? $empty), empty: @js($empty),
                   edit(shift) { this.form = { ...shift }; this.open = true; },
                   create() { this.form = { ...this.empty }; this.open = true; } }"
         @keydown.escape.window="open = false">

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="mr-auto text-xl font-bold">Presenze</h1>
            <div class="flex items-center gap-1 rounded-xl bg-white p-1 ring-1 ring-stone-200">
                <a href="{{ route('attendances.index', ['mese' => $prev]) }}" class="rounded-lg px-3 py-1.5 font-semibold text-stone-600 hover:bg-stone-100" aria-label="Mese precedente">‹</a>
                <span class="min-w-32 text-center font-semibold capitalize">{{ $month->translatedFormat('F Y') }}</span>
                <a href="{{ route('attendances.index', ['mese' => $next]) }}" class="rounded-lg px-3 py-1.5 font-semibold text-stone-600 hover:bg-stone-100" aria-label="Mese successivo">›</a>
            </div>
            <a href="{{ route('attendances.excel', ['mese' => $month->format('Y-m')]) }}" class="btn-light py-2">Esporta Excel</a>
            <button type="button" class="btn-primary py-2" @click="create()">+ Aggiungi turno</button>
        </div>


        <div class="mb-4 grid gap-4 lg:grid-cols-[1fr_2fr]">
            <div class="card p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Adesso in negozio</div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @forelse ($present as $a)
                        <span class="rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-800 ring-1 ring-brand-100">{{ $a->user->name }} · dalle {{ $a->started_at->format('H:i') }}</span>
                    @empty
                        <span class="text-sm text-stone-500">Nessuno.</span>
                    @endforelse
                </div>
                @if ($people->whereNull('badge')->isNotEmpty())
                    <p class="mt-3 text-xs text-stone-500">Senza badge: {{ $people->whereNull('badge')->pluck('name')->implode(', ') }}. Si assegna da <a href="{{ route('badges.index') }}" class="font-semibold text-brand-700">Badge</a>.</p>
                @endif
            </div>

            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                        <tr><th class="px-4 py-3">Persona</th><th class="px-4 py-3 text-right">Giorni</th><th class="px-4 py-3 text-right">Ore</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($totals as $t)
                            <tr>
                                <td class="px-4 py-2.5 font-semibold">{{ $t['name'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $t['days'] }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $hours($t['minutes']) }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($t['to_check'])
                                        <span class="badge bg-red-100 text-red-700">{{ $t['to_check'] }} da controllare</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-stone-500">Nessun turno in questo mese.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Persona</th>
                        <th class="px-4 py-3">Entrata</th>
                        <th class="px-4 py-3">Uscita</th>
                        <th class="px-4 py-3 text-right">Durata</th>
                        <th class="hidden px-4 py-3 md:table-cell">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($shifts as $a)
                        <tr class="cursor-pointer hover:bg-stone-50 {{ $a->ended_auto ? 'bg-red-50/60' : '' }}" title="{{ $a->origin() }}"
                            @click="edit(@js(['id' => $a->id, 'user_id' => $a->user_id, 'name' => $a->user->name, 'started_at' => $a->started_at->format('Y-m-d\TH:i'), 'ended_at' => $a->ended_at?->format('Y-m-d\TH:i') ?? '', 'note' => $a->note ?? '']))">
                            <td class="px-4 py-2.5 capitalize">{{ $a->started_at->translatedFormat('D d/m') }}</td>
                            <td class="px-4 py-2.5 font-semibold">{{ $a->user->name }}@if ($a->badge)<span class="ml-1 text-xs font-normal text-stone-400">· {{ $a->badge->number }}</span>@endif</td>
                            <td class="px-4 py-2.5 tabular-nums">{{ $a->started_at->format('H:i') }}</td>
                            <td class="px-4 py-2.5 tabular-nums">
                                @if (! $a->ended_at)
                                    <span class="badge bg-brand-50 text-brand-700">in corso</span>
                                @else
                                    {{ $a->ended_at->format('H:i') }}{{ $a->ended_at->isSameDay($a->started_at) ? '' : ' (' . $a->ended_at->format('d/m') . ')' }}
                                    @if ($a->ended_auto)
                                        <span class="badge ml-1 bg-red-100 text-red-700" title="Uscita non bippata: chiusa da Orio alla chiusura della giornata">da controllare</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $hours($a->minutes()) }}</td>
                            <td class="hidden px-4 py-2.5 text-stone-500 md:table-cell">
                                {{ $a->note }}
                                @if ($a->editor)
                                    <span class="text-xs">· corretto da {{ $a->editor->name }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div x-show="open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/40 p-3" @click.self="open = false">
            <div class="card w-full max-w-md p-5">
                <form method="POST" :action="form.id ? @js(route('attendances.update', '__ID__')).replace('__ID__', form.id) : @js(route('attendances.store'))">
                    @csrf
                    <template x-if="form.id"><input type="hidden" name="_method" value="PUT"></template>
                    <input type="hidden" name="attendance_id" :value="form.id">

                    <h2 class="mb-4 text-lg font-bold" x-text="form.id ? (form.name ? `Turno di ${form.name}` : 'Modifica turno') : 'Aggiungi turno'"></h2>

                    @if ($errors->any())
                        <div class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
                            @foreach ($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    <div class="space-y-3">
                        <template x-if="!form.id">
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Persona</label>
                                <select name="user_id" x-model="form.user_id" class="input" required>
                                    <option value="">Scegli…</option>
                                    @foreach ($people as $person)
                                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </template>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Entrata</label>
                                <input type="datetime-local" name="started_at" x-model="form.started_at" class="input" required>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-semibold">Uscita</label>
                                <input type="datetime-local" name="ended_at" x-model="form.ended_at" class="input">
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-semibold">Nota <span class="font-normal text-stone-500">(es. badge dimenticato)</span></label>
                            <input name="note" x-model="form.note" class="input" maxlength="255">
                        </div>
                        <p class="text-xs text-stone-500">Uscita vuota = turno ancora in corso. Le correzioni restano firmate con il tuo nome.</p>
                    </div>

                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" class="btn-light py-2" @click="open = false">Annulla</button>
                        <button class="btn-primary py-2" x-text="form.id ? 'Salva' : 'Aggiungi'"></button>
                    </div>
                </form>
                <form x-show="form.id" method="POST" :action="@js(route('attendances.destroy', '__ID__')).replace('__ID__', form.id)"
                      data-confirm="Eliminare questo turno?" data-confirm-label="Elimina" data-confirm-danger class="mt-3 border-t border-stone-200 pt-3 text-right">
                    @csrf
                    <input type="hidden" name="_method" value="DELETE">
                    <button class="text-sm font-semibold text-red-700">Elimina turno</button>
                </form>
            </div>
        </div>
    </div>
</x-layout>
