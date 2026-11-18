<x-layout title="Badge">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Badge</h1>
        @if ($badges->isNotEmpty())
            <a href="{{ route('badges.print') }}" target="_blank" class="btn-light py-2">Stampa tutti</a>
        @endif
        <form method="POST" action="{{ route('badges.store') }}" class="flex items-center gap-2">
            @csrf
            <input type="number" name="count" value="{{ $badges->isEmpty() ? 5 : 1 }}" min="1" max="50" class="input w-20 py-2 text-center" aria-label="Quanti badge">
            <button class="btn-primary py-2">+ Crea badge</button>
        </form>
    </div>

    @if ($errors->any())
        <p class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</p>
    @endif

    <p class="mb-4 text-sm text-stone-600">
        I badge sono numerati e si stampano una volta sola: ognuno si assegna a una persona. Quando qualcuno va via,
        <strong>prima</strong> metti il suo badge su «libero» (o direttamente sulla nuova persona), poi consegnalo:
        i turni già registrati restano a chi li ha fatti.
    </p>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr>
                    <th class="px-4 py-3">Badge</th>
                    <th class="px-4 py-3">Assegnato a</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($badges as $badge)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $badge->label() }}</div>
                            <div class="font-mono text-xs text-stone-400">{{ $badge->code }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('badges.update', $badge) }}" class="flex flex-wrap items-center gap-2"
                                  @if ($badge->user && in_array($badge->user_id, $present, true))
                                      data-confirm="{{ $badge->user->name }} risulta in negozio: il suo turno verrà chiuso adesso. Cambiare il badge?" data-confirm-label="Cambia"
                                  @endif>
                                @csrf
                                @method('PUT')
                                <select name="user_id" class="input w-56 py-2" onchange="this.form.requestSubmit()">
                                    <option value="">— libero —</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}" @selected($badge->user_id === $user->id)>
                                            {{ $user->name }}{{ $user->badge && $user->badge->isNot($badge) ? ' (ha ' . $user->badge->label() . ')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                @if ($badge->user && in_array($badge->user_id, $present, true))
                                    <span class="badge bg-brand-50 text-brand-700">in negozio</span>
                                @endif
                                <noscript><button class="btn-light py-2">Salva</button></noscript>
                            </form>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('badges.print', ['id' => $badge->id]) }}" target="_blank" class="mr-3 font-semibold text-brand-700">Stampa</a>
                            <form method="POST" action="{{ route('badges.replace', $badge) }}" class="inline"
                                  data-confirm="{{ $badge->label() }} perso? Quello attuale smetterà di funzionare e ne stampi uno nuovo con lo stesso numero." data-confirm-label="Sostituisci" data-confirm-danger>
                                @csrf
                                <button class="font-semibold text-red-700">Perso</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-stone-500">Nessun badge: creane quanti ne servono (es. 5), poi stampali e assegnali.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{-- Dispositivi che possono registrare le presenze (il tablet della cassa) --}}
    <h2 class="mb-2 mt-8 text-lg font-bold">Dispositivi abilitati alle presenze</h2>
    <p class="mb-4 text-sm text-stone-600">
        Le presenze si registrano solo dai dispositivi abilitati: da un altro computer o telefono il badge non funziona,
        anche con il login fatto. Se sul tablet vengono cancellati i dati di Chrome l'abilitazione si perde: genera un codice
        e fallo scrivere sul tablet al primo bip.
    </p>

    @if (session('device_code'))
        <div class="mb-4 rounded-xl bg-amber-50 p-4 text-center ring-1 ring-amber-200">
            <div class="text-sm text-amber-900">Codice da scrivere sul tablet, valido fino alle {{ session('device_code')['expires'] }} e una volta sola:</div>
            <div class="mt-1 font-mono text-4xl font-bold tracking-[0.3em] text-amber-950">{{ chunk_split(session('device_code')['code'], 3, ' ') }}</div>
        </div>
    @endif

    <div class="mb-4 grid gap-3 md:grid-cols-2">
        <div class="card p-4">
            <div class="font-semibold">Sei davanti al tablet?</div>
            @if ($thisDevice)
                <p class="mt-2 text-sm text-brand-800">Questo dispositivo è già abilitato ({{ $thisDevice->name }}).</p>
            @else
                <form method="POST" action="{{ route('devices.enable') }}" class="mt-2 flex gap-2">
                    @csrf
                    <input name="name" value="Tablet cassa" maxlength="60" class="input py-2" aria-label="Nome del dispositivo" required>
                    <button class="btn-primary shrink-0 py-2">Abilita questo dispositivo</button>
                </form>
            @endif
        </div>
        <div class="card p-4">
            <div class="font-semibold">Sei da un'altra parte?</div>
            <form method="POST" action="{{ route('devices.code') }}" class="mt-2 flex items-center gap-3">
                @csrf
                <button class="btn-light shrink-0 py-2">Genera codice di 6 cifre</button>
                <span class="text-xs text-stone-500">Vale 10 minuti: si scrive sul tablet quando il badge dice «non abilitato».</span>
            </form>
        </div>
    </div>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                <tr><th class="px-4 py-3">Dispositivo</th><th class="px-4 py-3">Abilitato</th><th class="px-4 py-3">Ultimo bip</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($devices as $device)
                    <tr class="{{ $device->revoked_at ? 'text-stone-400' : '' }}">
                        <td class="px-4 py-3 font-semibold">
                            {{ $device->name }}
                            @if ($thisDevice?->is($device))
                                <span class="ml-1 text-xs font-normal text-stone-400">(questo)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">{{ $device->created_at->format('d/m/Y H:i') }} da {{ $device->enabler->name }}{{ $device->enabled_via === 'codice' ? ' (con codice)' : '' }}</td>
                        <td class="px-4 py-3">{{ $device->last_used_at ? $device->last_used_at->format('d/m/Y H:i') . ($device->last_ip ? " · {$device->last_ip}" : '') : '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if ($device->revoked_at)
                                Revocato il {{ $device->revoked_at->format('d/m/Y') }}
                            @else
                                <form method="POST" action="{{ route('devices.revoke', $device) }}" class="inline"
                                      data-confirm="Revocare {{ $device->name }}? Non potrà più registrare presenze finché non viene riabilitato." data-confirm-label="Revoca" data-confirm-danger>
                                    @csrf
                                    @method('DELETE')
                                    <button class="font-semibold text-red-700">Revoca</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-stone-500">Nessun dispositivo abilitato: le presenze non si possono ancora registrare.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
