<x-layout title="Stampante fiscale">
    <h1 class="mb-4 text-xl font-bold">Stampante fiscale</h1>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card p-4">
            <h2 class="mb-3 font-bold">Configurazione</h2>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-stone-500">Driver</dt>
                <dd>
                    @switch($config['driver'])
                        @case('axon') Axon Micrelec DADO RT (WyCash 600) @break
                        @case('epson') Epson FPMate @break
                        @default Simulata: nessuno scontrino viene stampato
                    @endswitch
                </dd>
                <dt class="text-stone-500">Indirizzo</dt>
                <dd class="font-mono">{{ $base ?? 'non impostato' }}</dd>
                @if ($config['driver'] === 'axon')
                    <dt class="text-stone-500">Reparti</dt>
                    <dd>
                        @foreach ($config['departments'] as $vat => $department)
                            IVA {{ $vat }}% → reparto {{ $department }}@if (! $loop->last), @endif
                        @endforeach
                    </dd>
                    <dt class="text-stone-500">Pagamenti</dt>
                    <dd>contanti → n. {{ $config['payment_cash'] }}, carta/bancomat → n. {{ $config['payment_card'] }}</dd>
                @endif
            </dl>
            <p class="mt-3 text-xs text-stone-500">Si cambia nel file <code>.env</code> del server (voci <code>FISCAL_PRINTER_*</code>).</p>
        </div>

        @if ($config['driver'] === 'axon' && $base)
            <div class="card p-4" x-data="{
                busy: false, error: null, status: null, reply: null, pkt: '?/',
                async readStatus() {
                    this.busy = true; this.error = null; this.status = null;
                    try { this.status = await axonPrinter.status(@js($base)); } catch (e) { this.error = e.message; }
                    this.busy = false;
                },
                async send() {
                    if (! /^[?:{|,;eOfpBQ]/.test(this.pkt) && ! await askConfirm({ title: 'Inviare il comando alla stampante?', message: 'Questo comando può stampare o modificare la stampante fiscale.', items: [{ label: 'Comando', value: this.pkt }], confirmLabel: 'Invia', danger: true })) return;
                    this.busy = true; this.error = null; this.reply = null;
                    try { this.reply = await axonPrinter.command(@js($base), this.pkt); } catch (e) { this.error = e.message; }
                    this.busy = false;
                },
            }">
                <h2 class="mb-1 font-bold">Prova di comunicazione</h2>
                <p class="mb-3 text-sm text-stone-500">Va aperta dal tablet della cassa: è il suo browser a parlare con la stampante.</p>
                <button type="button" class="btn-primary" :disabled="busy" @click="readStatus()">Leggi stato stampante</button>

                <template x-if="status">
                    <div class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                        Il tablet comunica con la stampante. Ultimo documento:
                        <span class="font-semibold" x-text="`chiusura ${status.ej?.lastdoc?.zno ?? '?'}, documento ${status.ej?.lastdoc?.docno ?? '?'}`"></span>
                        <pre class="mt-2 overflow-x-auto text-xs text-stone-700" x-text="JSON.stringify(status, null, 2)"></pre>
                    </div>
                </template>

                <h3 class="mb-1 mt-5 font-semibold">Comando di protocollo</h3>
                <p class="mb-2 text-xs text-stone-500">Per leggere la programmazione della stampante (reparti, pagamenti, POS). I comandi di stampa chiedono conferma.</p>
                <form class="flex gap-2" @submit.prevent="send()">
                    <input type="text" class="input flex-1 font-mono" x-model="pkt">
                    <button class="btn-light" :disabled="busy">Invia</button>
                </form>
                <pre x-show="reply !== null" class="mt-2 overflow-x-auto rounded-lg bg-stone-100 p-2 text-xs" x-text="reply"></pre>
                <p x-show="error" x-text="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
            </div>
        @endif
    </div>

    @if ($config['driver'] === 'axon')
        <div class="card mt-4 space-y-3 p-4 text-sm">
            <h2 class="font-bold">Se il tablet non raggiunge la stampante</h2>
            <p>Orio è in <code>https</code>, la stampante risponde solo in <code>http</code> sulla rete del negozio, e Chrome blocca queste chiamate se non lo autorizzi:</p>
            <ol class="list-decimal space-y-1 pl-5">
                <li>Premi "Leggi stato stampante": se Chrome chiede di consentire l'accesso ai <strong>dispositivi della rete locale</strong>, rispondi <strong>Consenti</strong>.</li>
                <li>Se non compare nessuna richiesta: apri <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>, scrivi <code class="font-mono">{{ $base ?? 'http://IP-stampante' }}</code>, attiva l'opzione e riavvia Chrome.</li>
                <li>Controlla che tablet e stampante siano sulla stessa rete e che l'indirizzo sia quello giusto (lo stampa la stampante nel report di configurazione).</li>
            </ol>
            <h2 class="pt-2 font-bold">Da verificare con l'installatore</h2>
            <ul class="list-disc space-y-1 pl-5">
                <li>Quali numeri di pagamento sono <em>contanti</em> e <em>carta/bancomat</em> e quali reparti hanno l'IVA al 22%.</li>
                <li>Se il POS bancario è collegato alla stampante: in quel caso il pagamento con carta parte dal POS quando Orio stampa lo scontrino, e l'operatore non deve digitare l'importo.</li>
            </ul>
        </div>
    @endif
</x-layout>
