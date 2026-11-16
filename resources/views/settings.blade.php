@php
    // Valore del campo: quello appena inviato (se il salvataggio ha dato errore) o quello attuale
    $v = fn (string $key) => old($key, data_get($c, $key));
@endphp
<x-layout title="Configurazione">
    <div class="mx-auto max-w-4xl">
        <h1 class="text-xl font-bold">Configurazione</h1>
        <p class="mb-4 text-sm text-stone-500">Indirizzi dei dispositivi del negozio, terminali carta e collegamenti. Le chiavi e le password vengono salvate cifrate e non si rivedono: per cambiarle scrivi quella nuova.</p>

        <nav class="mb-4 flex flex-wrap gap-2 text-sm">
            @foreach (['negozio' => 'Negozio', 'stampante' => 'Stampante fiscale', 'cassetto' => 'Cassetto contanti', 'pos' => 'Terminali carta', 'fattura24' => 'Fattura24', 'chiusura' => 'Chiusura giornata', 'tea' => 'Ordini TEA'] as $anchor => $label)
                <a href="#{{ $anchor }}" class="rounded-full bg-white px-3 py-1.5 font-semibold text-stone-600 ring-1 ring-stone-200 hover:bg-stone-50">{{ $label }}</a>
            @endforeach
        </nav>

        @if ($errors->any())
            <div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200">
                <div class="font-semibold">Configurazione non salvata:</div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-4" autocomplete="off">
            @csrf
            @method('PUT')

            {{-- Negozio --}}
            <section id="negozio" class="card scroll-mt-20 p-5">
                <h2 class="font-bold">Negozio</h2>
                <p class="mb-4 text-sm text-stone-500">Intestazione dell'anteprima scontrino in Orio (quella stampata la programma l'installatore sulla stampante).</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-sm font-semibold">Ragione sociale</span>
                        <input name="company[name]" value="{{ $v('company.name') }}" class="input" required maxlength="100">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Indirizzo</span>
                        <input name="company[address]" value="{{ $v('company.address') }}" class="input" maxlength="150">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Partita IVA</span>
                        <input name="company[vat_code]" value="{{ $v('company.vat_code') }}" class="input" maxlength="20">
                    </label>
                </div>
            </section>

            {{-- Stampante fiscale --}}
            <section id="stampante" class="card scroll-mt-20 p-5" x-data="{ driver: @js($v('fiscal_printer.driver')) }">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-bold">Stampante fiscale</h2>
                    <a href="{{ route('fiscal-printer') }}" class="text-sm font-semibold text-brand-700">Prova di comunicazione →</a>
                </div>
                <p class="mb-4 text-sm text-stone-500">La stampante è chiamata dal browser del tablet di cassa: l'indirizzo è quello sulla rete del negozio (lo stampa la stampante nel report di configurazione).</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Modello</span>
                        <select name="fiscal_printer[driver]" class="input" x-model="driver">
                            @foreach ($drivers['fiscal_printer'] as $key => $label)
                                <option value="{{ $key }}" @selected($v('fiscal_printer.driver') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Indirizzo IP</span>
                        <input name="fiscal_printer[host]" value="{{ $v('fiscal_printer.host') }}" class="input font-mono" placeholder="192.168.1.50" :required="driver !== 'fake'">
                    </label>
                </div>
                <div class="mt-4" x-show="driver === 'axon'">
                    <div class="mb-1 text-sm font-semibold">Reparti per aliquota IVA</div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($departments as $rate => $department)
                            <label class="flex items-center gap-2 rounded-lg px-3 py-2 ring-1 ring-stone-200">
                                <span class="w-14 shrink-0 text-sm text-stone-500">IVA {{ $rate }}%</span>
                                <input type="number" min="1" max="99" name="fiscal_printer[departments][{{ $rate }}]" value="{{ old("fiscal_printer.departments.{$rate}", $department) }}" class="input py-1.5" required>
                            </label>
                        @endforeach
                    </div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold">N. pagamento contanti</span>
                            <input type="number" min="1" max="99" name="fiscal_printer[payment_cash]" value="{{ $v('fiscal_printer.payment_cash') }}" class="input" required>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-semibold">N. pagamento carta / bancomat</span>
                            <input type="number" min="1" max="99" name="fiscal_printer[payment_card]" value="{{ $v('fiscal_printer.payment_card') }}" class="input" required>
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-stone-500">Numeri programmati sulla stampante: chiedili all'installatore.</p>
                </div>
            </section>

            {{-- Cassetto contanti --}}
            <section id="cassetto" class="card scroll-mt-20 p-5" x-data="{ driver: @js($v('vne.driver')) }">
                <h2 class="font-bold">Cassetto automatico contanti (VNE)</h2>
                <p class="mb-4 text-sm text-stone-500">Il cassetto che accetta i contanti e dà il resto.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Collegamento</span>
                        <select name="vne[driver]" class="input" x-model="driver">
                            @foreach ($drivers['vne'] as $key => $label)
                                <option value="{{ $key }}" @selected($v('vne.driver') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Indirizzo IP</span>
                        <input name="vne[host]" value="{{ $v('vne.host') }}" class="input font-mono" placeholder="192.168.1.60" :required="driver === 'direct'">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Nome operatore sul cassetto</span>
                        <input name="vne[op_name]" value="{{ $v('vne.op_name') }}" class="input" maxlength="30">
                    </label>
                </div>
            </section>

            {{-- Terminali carta --}}
            <section id="pos" class="card scroll-mt-20 p-5" x-data="settingsPos(@js(old('terminals', $terminals)), @js($v('pos.driver')), @js($defaultKeyHint))">
                <h2 class="font-bold">Terminali carta (MultiSafepay)</h2>
                <p class="mb-4 text-sm text-stone-500">SmartPOS e telefoni Tap to Pay. Con più terminali attivi la cassa chiede su quale incassare.</p>
                <label class="block max-w-md">
                    <span class="mb-1 block text-sm font-semibold">Collegamento</span>
                    <select name="pos[driver]" class="input" x-model="driver">
                        @foreach ($drivers['pos'] as $key => $label)
                            <option value="{{ $key }}" @selected($v('pos.driver') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="mt-4 space-y-3">
                    <template x-for="(t, i) in terminals" :key="t.uid">
                        <div class="rounded-xl p-3 ring-1" :class="t.active ? 'ring-stone-200' : 'bg-stone-50 ring-stone-200 opacity-75'">
                            <input type="hidden" :name="`terminals[${i}][original]`" :value="t.original ?? ''">
                            <input type="hidden" :name="`terminals[${i}][key_hint]`" :value="t.key_hint ?? ''">
                            <div class="grid gap-2 sm:grid-cols-[1.2fr_1fr_1.4fr]">
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-stone-500">Nome in cassa</span>
                                    <input class="input" :name="`terminals[${i}][name]`" x-model="t.name" placeholder="Telefono Tap to Pay" required maxlength="40">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-stone-500">TID</span>
                                    <input class="input font-mono" :name="`terminals[${i}][id]`" x-model="t.id" placeholder="000001AB" required maxlength="30">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-stone-500">Chiave API del gruppo terminali</span>
                                    <input class="input font-mono" type="password" :name="`terminals[${i}][api_key]`" x-model="t.api_key" autocomplete="new-password"
                                           :placeholder="t.key_hint ? `Salvata (${t.key_hint}): vuoto per lasciarla` : (defaultKey ? `Vuoto = chiave del .env (${defaultKey})` : 'Incolla la chiave')">
                                </label>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" value="1" class="h-5 w-5 accent-brand-600" :name="`terminals[${i}][active]`" x-model="t.active">
                                    <span class="font-semibold">Attivo in cassa</span>
                                </label>
                                <button type="button" class="font-semibold text-brand-700 disabled:text-stone-400" :disabled="!t.original || t.testing" @click="test(t)"
                                        :title="t.original ? '' : 'Salva prima la configurazione'">Prova collegamento</button>
                                <button type="button" class="font-semibold text-red-700" @click="remove(i)">Rimuovi</button>
                                <span x-show="t.result" x-text="t.result?.message" :class="t.result?.ok ? 'text-emerald-700' : 'text-red-700'"></span>
                            </div>
                        </div>
                    </template>
                    <p x-show="!terminals.length" class="rounded-xl bg-stone-50 px-3 py-3 text-sm text-stone-500">Nessun terminale.</p>
                    <button type="button" class="btn-light" @click="add()">+ Aggiungi terminale</button>
                </div>

                <details class="mt-4 text-sm text-stone-600">
                    <summary class="cursor-pointer font-semibold text-stone-700">Dove trovo TID e chiave?</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>TID</strong>: nell'app MultiSafepay del terminale o del telefono, in Features → About, alla voce "TID".</li>
                        <li><strong>Chiave API</strong>: nel pannello MultiSafepay, Point of Sale → Terminals → Manage groups, quella del <em>gruppo</em> del terminale (non quella del sito web). Ogni telefono Tap to Pay crea un suo gruppo, quindi ha la sua chiave.</li>
                        <li>Sul terminale va attivato il <strong>Cloud mode</strong> (Features → Payment), altrimenti non riceve gli importi da Orio.</li>
                        <li>"Prova collegamento" controlla la chiave senza muovere soldi; il TID si verifica col primo pagamento di prova da 1 €.</li>
                    </ul>
                </details>
            </section>

            {{-- Fattura24 --}}
            <section id="fattura24" class="card scroll-mt-20 p-5">
                <h2 class="font-bold">Fattura24</h2>
                <p class="mb-4 text-sm text-stone-500">Per le fatture emesse dalla cassa.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Chiave API</span>
                        <input type="password" name="fattura24[api_key]" class="input font-mono" autocomplete="new-password"
                               placeholder="{{ $secrets['fattura24.api_key'] ? 'Salvata (' . $secrets['fattura24.api_key'] . '): vuoto per lasciarla' : 'Non impostata' }}">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Sezionale (ID numeratore) <span class="font-normal text-stone-500">facoltativo</span></span>
                        <input name="fattura24[id_numerator]" value="{{ $v('fattura24.id_numerator') }}" class="input" maxlength="50">
                    </label>
                </div>
            </section>

            {{-- Chiusura giornata --}}
            <section id="chiusura" class="card scroll-mt-20 p-5">
                <h2 class="font-bold">Chiusura giornata</h2>
                <p class="mb-4 text-sm text-stone-500">Promemoria per la chiusura fiscale e avviso via email se a fine giornata manca.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Avviso in cassa dalle</span>
                        <input type="time" name="closure[reminder_at]" value="{{ $v('closure.reminder_at') }}" class="input" required>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Email se non chiusa alle</span>
                        <input type="time" name="closure[alert_at]" value="{{ $v('closure.alert_at') }}" class="input" required>
                    </label>
                    <label class="block sm:col-span-3">
                        <span class="mb-1 block text-sm font-semibold">Destinatari email <span class="font-normal text-stone-500">separati da virgola, oltre agli admin con email</span></span>
                        <input name="closure[alert_emails]" value="{{ old('closure.alert_emails', implode(', ', $c['closure']['alert_emails'])) }}" class="input">
                    </label>
                </div>
            </section>

            {{-- Ordini TEA --}}
            <section id="tea" class="card scroll-mt-20 p-5" x-data="{ testing: false, result: null }">
                <h2 class="font-bold">Ordini a TEA</h2>
                <p class="mb-4 text-sm text-stone-500">Collegamento a teaerp per gli ordini di riassortimento, e indirizzo del negozio per il ritiro.</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Indirizzo teaerp</span>
                        <input type="url" name="tea[url]" value="{{ $v('tea.url') }}" class="input font-mono" placeholder="https://…">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Utente</span>
                        <input name="tea[user]" value="{{ $v('tea.user') }}" class="input font-mono" autocapitalize="none">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Password</span>
                        <input type="password" name="tea[password]" class="input font-mono" autocomplete="new-password"
                               placeholder="{{ $secrets['tea.password'] ? 'Salvata (' . $secrets['tea.password'] . '): vuoto per lasciarla' : 'Non impostata' }}">
                    </label>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                    <button type="button" class="font-semibold text-brand-700" :disabled="testing"
                            @click="testing = true; result = null; axios.post(@js(route('settings.test-tea'))).then(r => result = r.data).catch(e => result = { ok: false, message: e.response?.data?.message ?? e.message }).finally(() => testing = false)">
                        Prova collegamento
                    </button>
                    <span class="text-stone-500" x-show="!result && !testing">usa i dati salvati, senza creare ordini</span>
                    <span x-show="testing" class="text-stone-500">…</span>
                    <span x-show="result" x-text="result?.message" :class="result?.ok ? 'text-emerald-700' : 'text-red-700'"></span>
                </div>

                <h3 class="mb-2 mt-5 text-sm font-bold">Indirizzo di ritiro sull'ordine</h3>
                <div class="grid gap-3 sm:grid-cols-6">
                    <label class="block sm:col-span-3">
                        <span class="mb-1 block text-sm font-semibold">Presso</span>
                        <input name="tea[shipping][company]" value="{{ $v('tea.shipping.company') }}" class="input" required maxlength="100">
                    </label>
                    <label class="block sm:col-span-3">
                        <span class="mb-1 block text-sm font-semibold">Via e numero negozio</span>
                        <input name="tea[shipping][street]" value="{{ $v('tea.shipping.street') }}" class="input" required maxlength="100">
                    </label>
                    <label class="block sm:col-span-3">
                        <span class="mb-1 block text-sm font-semibold">Città</span>
                        <input name="tea[shipping][city]" value="{{ $v('tea.shipping.city') }}" class="input" required maxlength="60">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-sm font-semibold">CAP</span>
                        <input name="tea[shipping][postcode]" value="{{ $v('tea.shipping.postcode') }}" class="input" required maxlength="10">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold">Prov.</span>
                        <input name="tea[shipping][region]" value="{{ $v('tea.shipping.region') }}" class="input uppercase" required maxlength="5">
                    </label>
                </div>
            </section>

            <div class="sticky bottom-0 -mx-4 flex items-center justify-end gap-3 border-t border-stone-200 bg-stone-100/95 px-4 py-3 backdrop-blur">
                <span class="mr-auto text-xs text-stone-500">Le modifiche valgono subito per tutte le postazioni.</span>
                <button class="btn-primary">Salva configurazione</button>
            </div>
        </form>
    </div>
</x-layout>
