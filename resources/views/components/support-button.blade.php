{{-- "Segnala un problema" (icona cuffie), come su ERP ed emmycasa: la segnalazione va al task manager --}}
<div x-data="supportRequest" class="contents">
    <button type="button" @click="show()" :disabled="capturing" title="Segnala un problema" aria-label="Segnala un problema"
            class="rounded-lg p-2 text-stone-600 hover:bg-stone-100 hover:text-brand-700 disabled:opacity-50">
        <svg x-show="!capturing" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 14v-3a9 9 0 0 1 18 0v3"/><path d="M21 15a2 2 0 0 1-2 2h-1v-6h1a2 2 0 0 1 2 2z"/><path d="M3 15a2 2 0 0 0 2 2h1v-6H5a2 2 0 0 0-2 2z"/><path d="M18 17v1a3 3 0 0 1-3 3h-3"/>
        </svg>
        <svg x-show="capturing" x-cloak class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.6"/></svg>
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4"
             @keydown.escape.window="open && !sending && (open = false)" @click.self="!sending && (open = false)">
            <div class="card max-h-[90vh] w-full max-w-lg overflow-y-auto p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="support-title">
                <h2 id="support-title" class="text-lg font-bold">Segnala un problema</h2>
                <p class="mt-1 text-sm text-stone-500">Arriva direttamente a chi segue Orio, con la pagina in cui ti trovi.</p>

                <template x-if="sent">
                    <p class="mt-4 rounded-xl bg-brand-50 px-4 py-3 font-semibold text-brand-700" x-text="sent"></p>
                </template>

                <div x-show="!sent" class="mt-4 space-y-3">
                    <label class="block text-sm font-semibold">Titolo
                        <input x-ref="title" x-model="title" maxlength="200" class="input mt-1" placeholder="Es. lo scontrino non parte">
                    </label>
                    <label class="block text-sm font-semibold">Cosa è successo
                        <textarea x-model="description" rows="4" class="input mt-1" placeholder="Cosa stavi facendo, cosa ti aspettavi, cosa è comparso"></textarea>
                    </label>

                    <div>
                        <div class="text-sm font-semibold">Screenshot della pagina</div>
                        <template x-if="screenshotUrl">
                            <img :src="screenshotUrl" alt="Screenshot della pagina" class="mt-1 max-h-48 w-full rounded-lg object-contain ring-1 ring-stone-200">
                        </template>
                        <p x-show="!screenshotUrl" class="mt-1 text-sm text-stone-400">Screenshot non disponibile.</p>
                        <label x-show="screenshotUrl" class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" x-model="includeScreenshot"> Allega lo screenshot</label>
                    </div>

                    <label class="block text-sm font-semibold">Altro allegato (facoltativo)
                        <input x-ref="extra" type="file" accept="image/*,.pdf,.zip,.doc,.docx,.xls,.xlsx,.csv,.txt" class="mt-1 block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-semibold">
                    </label>
                    <p class="truncate text-xs text-stone-400" x-text="`Pagina: ${pageUrl}`"></p>
                    <p x-show="error" x-text="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3" x-show="!sent">
                    <button type="button" class="btn-light py-3" :disabled="sending" @click="open = false">Annulla</button>
                    <button type="button" class="btn-primary py-3" :disabled="sending" @click="send()" x-text="sending ? 'Invio…' : 'Invia segnalazione'"></button>
                </div>
            </div>
        </div>
    </template>
</div>
