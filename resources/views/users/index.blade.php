<x-layout title="Utenti">
    @php
        $empty = ['id' => null, 'name' => '', 'username' => '', 'is_admin' => false, 'active' => true];
        // Dopo un errore di validazione la finestra si riapre con i dati appena inseriti
        $reopen = $errors->any() ? [
            'id' => old('user_id') ? (int) old('user_id') : null,
            'name' => old('name', ''),
            'username' => old('username', ''),
            'is_admin' => (bool) old('is_admin'),
            'active' => (bool) old('active'),
        ] : null;
    @endphp

    <div x-data="{ open: @js((bool) $reopen), form: @js($reopen ?? $empty), empty: @js($empty),
                   edit(user) { this.form = { ...user }; this.open = true; },
                   create() { this.form = { ...this.empty }; this.open = true; } }"
         @keydown.escape.window="open = false">

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="mr-auto text-xl font-bold">Utenti</h1>
            <span class="text-sm text-stone-500">Gli operatori lavorano solo su cassa e vendite: non vedono la modifica dei prezzi, la sincronizzazione né questa pagina.</span>
            <button type="button" class="btn-primary py-2" @click="create()">+ Nuovo utente</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3">Nome</th>
                        <th class="px-4 py-3">Username</th>
                        <th class="px-4 py-3">Ruolo</th>
                        <th class="px-4 py-3">Stato</th>
                        <th class="px-4 py-3 text-right">Vendite</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($users as $user)
                        <tr class="cursor-pointer hover:bg-stone-50 {{ $user->active ? '' : 'text-stone-400' }}"
                            @click="edit(@js($user->only(['id', 'name', 'username', 'is_admin', 'active'])))">
                            <td class="px-4 py-3 font-semibold">
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <span class="ml-1 text-xs font-normal text-stone-400">(tu)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono">{{ $user->username }}</td>
                            <td class="px-4 py-3">
                                @if ($user->is_admin)
                                    <span class="badge bg-brand-50 text-brand-700">Admin</span>
                                @else
                                    <span class="badge bg-stone-100 text-stone-700">Operatore</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($user->active)
                                    <span class="badge bg-green-100 text-green-800">Attivo</span>
                                @else
                                    <span class="badge bg-red-100 text-red-700">Disattivato</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $user->sales_count }}</td>
                            <td class="px-4 py-3 text-right text-brand-700">Modifica</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div x-show="open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/40 p-3" @click.self="open = false">
            <form method="POST" class="card w-full max-w-md p-5"
                  :action="form.id ? @js(route('users.update', '__ID__')).replace('__ID__', form.id) : @js(route('users.store'))">
                @csrf
                <template x-if="form.id"><input type="hidden" name="_method" value="PUT"></template>
                <input type="hidden" name="user_id" :value="form.id">

                <h2 class="mb-4 text-lg font-bold" x-text="form.id ? 'Modifica utente' : 'Nuovo utente'"></h2>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">
                        @foreach ($errors->all() as $error)
                            <div>{{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold">Nome</label>
                        <input name="name" x-model="form.name" class="input" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold">Username <span class="font-normal text-stone-500">(per l'accesso)</span></label>
                        <input name="username" x-model="form.username" class="input font-mono" autocapitalize="none" autocomplete="off" required
                               @input="form.username = form.username.toLowerCase()">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold">Password</label>
                        <input name="password" type="password" class="input" autocomplete="new-password" minlength="6" :required="!form.id"
                               :placeholder="form.id ? 'Lascia vuoto per non cambiarla' : 'Almeno 6 caratteri'">
                    </div>
                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <label class="flex items-center gap-2 rounded-lg px-3 py-2 ring-1 ring-stone-200">
                            <input type="checkbox" name="is_admin" value="1" x-model="form.is_admin" class="h-5 w-5 accent-brand-600">
                            <span class="text-sm font-semibold">Admin</span>
                        </label>
                        <label class="flex items-center gap-2 rounded-lg px-3 py-2 ring-1 ring-stone-200">
                            <input type="checkbox" name="active" value="1" x-model="form.active" class="h-5 w-5 accent-brand-600">
                            <span class="text-sm font-semibold">Attivo</span>
                        </label>
                    </div>
                    <p class="text-xs text-stone-500" x-show="!form.is_admin">Operatore: cassa, vendite, clienti e chiusura; prezzi e sincronizzazione bloccati.</p>
                    <p class="text-xs text-stone-500" x-show="form.is_admin">Admin: tutto, compresi prezzi, sincronizzazione prodotti e gestione utenti.</p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-light py-2" @click="open = false">Annulla</button>
                    <button class="btn-primary py-2" x-text="form.id ? 'Salva' : 'Crea utente'"></button>
                </div>
            </form>
        </div>
    </div>
</x-layout>
