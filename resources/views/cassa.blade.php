<x-layout title="Cassa">
    <div x-data="cassa(@js($initial))" class="grid gap-5 lg:grid-cols-[1fr_360px]">
        {{-- Scansione e righe della vendita --}}
        <section class="space-y-4">
            <div class="card relative p-3">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input x-ref="scan" x-model="scan" @input="onScanInput()" @keydown.enter.prevent="submitScan()" @keydown.escape="results = []; scan = ''"
                               class="input py-3.5 pl-11 text-lg" placeholder="Bip del codice a barre o cerca per nome"
                               autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false">
                        <svg class="pointer-events-none absolute top-1/2 left-3.5 h-6 w-6 -translate-y-1/2 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 5v14M7 5v14M11 5v14M14 5v14M18 5v14M21 5v14"/></svg>
                    </div>
                    <button type="button" @click="openQty()" class="btn min-w-20 bg-amber-400 text-xl text-amber-950 hover:bg-amber-300" title="Quantità per il prossimo prodotto">
                        ×<span x-text="pendingQty"></span>
                    </button>
                </div>
                <p x-show="scanMessage" x-text="scanMessage" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>

                {{-- Risultati ricerca / scelta tra piu' prodotti con lo stesso codice --}}
                <div x-show="results.length || resultsTitle" x-cloak @click.outside="results = []; resultsTitle = null"
                     class="absolute inset-x-3 top-full z-20 mt-1 max-h-[60vh] overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-stone-200">
                    <div x-show="resultsTitle" x-text="resultsTitle" class="px-4 py-2 text-sm font-semibold text-stone-500"></div>
                    <template x-for="product in results" :key="product.product_id">
                        <button type="button" @click="addProduct(product)" class="flex w-full items-center gap-3 border-t border-stone-100 px-4 py-2.5 text-left hover:bg-brand-50">
                            <img :src="product.image_url" x-show="product.image_url" class="h-11 w-11 rounded-lg object-cover ring-1 ring-stone-200" alt="">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold" x-text="product.description"></div>
                                <div class="text-xs text-stone-500" x-text="[product.sku, product.format, product.ean].filter(Boolean).join(' · ')"></div>
                            </div>
                            <div class="font-bold tabular-nums" x-text="euro(Math.round(parseFloat(product.price.replace(',', '.')) * 100))"></div>
                        </button>
                    </template>
                </div>
            </div>

            <div class="card p-3">
                <div class="mb-2 flex items-center justify-between px-1">
                    <h1 class="font-bold">Vendita <span class="font-normal text-stone-500" x-show="rows.length" x-text="`· ${totalPieces} pz`"></span>
                        <span class="ml-2 text-xs font-normal" x-show="saveState" x-cloak
                              :class="saveState === 'error' ? 'text-red-600' : 'text-stone-400'"
                              x-text="{ saving: 'salvataggio…', saved: '✓ bozza salvata', error: 'bozza non salvata' }[saveState]"></span></h1>
                    <div class="flex gap-2">
                        <button type="button" class="btn-light py-2 text-sm" @click="newSale()" x-show="rows.length">Svuota</button>
                    </div>
                </div>

                <div x-show="!rows.length" class="rounded-xl border-2 border-dashed border-stone-200 py-14 text-center text-stone-400">
                    Passa il lettore sul codice a barre del prodotto
                </div>

                <template x-for="(row, index) in rows" :key="row.key">
                    <div class="flex flex-wrap items-center gap-3 border-t border-stone-100 px-1 py-2.5 transition-colors first:border-0 md:flex-nowrap"
                         :class="flashKey === row.key && 'bg-brand-50'">
                        <img :src="row.image_url" x-show="row.image_url" class="h-12 w-12 shrink-0 rounded-lg object-cover ring-1 ring-stone-200" alt="">
                        <div class="min-w-0 flex-1 basis-[calc(100%-4rem)] md:basis-auto">
                            <div class="line-clamp-2 font-semibold leading-tight" x-text="row.description"></div>
                            <div class="text-xs text-stone-500" x-text="[row.sku, row.format].filter(Boolean).join(' · ')"></div>
                        </div>
                        <div class="flex items-center">
                            <button type="button" @click="changeQty(row, -1)" class="h-11 w-11 rounded-l-xl bg-stone-100 text-xl font-bold hover:bg-stone-200" aria-label="Diminuisci">−</button>
                            <input class="h-11 w-14 border-y border-stone-200 text-center text-lg font-semibold [appearance:textfield] focus:outline-none" inputmode="numeric" x-model.number="row.qty">
                            <button type="button" @click="changeQty(row, 1)" class="h-11 w-11 rounded-r-xl bg-stone-100 text-xl font-bold hover:bg-stone-200" aria-label="Aumenta">+</button>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-stone-400">€</span>
                            <input class="input w-20 py-2 text-right tabular-nums" inputmode="decimal" x-model="row.price" placeholder="0,00">
                        </div>
                        <div class="w-20 text-right font-bold tabular-nums" x-text="euro(lineCents(row))"></div>
                        <button type="button" class="flex h-11 w-9 items-center justify-center rounded-lg text-2xl text-stone-400 hover:bg-red-50 hover:text-red-600" @click="removeRow(index)" aria-label="Rimuovi riga">×</button>
                    </div>
                </template>
            </div>
        </section>

        {{-- Quantita' per il prossimo bip --}}
        <div x-show="qtyModal" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-stone-900/50 p-4" @keydown.escape.window="qtyModal = false">
            <div class="card w-full max-w-xs p-5 shadow-xl" @click.outside="qtyModal = false">
                <div class="mb-3 font-bold">Quantità per il prossimo prodotto</div>
                <input x-model="qtyInput" x-effect="qtyModal && $nextTick(() => $el.focus())" @keydown.enter.prevent="setPendingQty(qtyInput)"
                       class="input mb-3 text-center text-3xl font-bold" inputmode="numeric" placeholder="1">
                <div class="grid grid-cols-4 gap-2">
                    <template x-for="n in [2, 3, 4, 5, 6, 10, 12, 24]">
                        <button type="button" class="btn-light py-3 text-lg" @click="setPendingQty(n)" x-text="n"></button>
                    </template>
                </div>
                <button type="button" class="btn-primary mt-3 w-full" @click="setPendingQty(qtyInput)">Conferma</button>
            </div>
        </div>

        {{-- Documento, cliente e pagamento --}}
        <aside class="space-y-4">
            <div class="card p-4">
                <div class="grid grid-cols-2 gap-1 rounded-xl bg-stone-100 p-1">
                    <button type="button" class="rounded-lg py-2.5 font-semibold" :class="documentType === 'scontrino' ? 'bg-white shadow' : 'text-stone-500'" @click="documentType = 'scontrino'">Scontrino</button>
                    <button type="button" class="rounded-lg py-2.5 font-semibold" :class="documentType === 'fattura' ? 'bg-white shadow' : 'text-stone-500'" @click="documentType = 'fattura'">Fattura</button>
                </div>

                <div x-show="documentType === 'fattura'" x-cloak class="mt-4 space-y-3" @customer:saved.window="onCustomerSaved($event.detail)">
                    <template x-if="!customer.id">
                        <div class="space-y-3">
                            <div class="flex gap-2">
                                <input class="input" placeholder="Partita IVA" inputmode="numeric" maxlength="13" x-model="vat" @keydown.enter.prevent="lookupVat()">
                                <button type="button" class="btn-primary shrink-0 py-2.5" @click="lookupVat()" :disabled="lookingUp || vat.length < 11">
                                    <span x-show="!lookingUp">Cerca</span><span x-show="lookingUp">…</span>
                                </button>
                            </div>
                            <p x-show="customerError" x-text="customerError" class="text-sm text-amber-700"></p>
                            <div class="relative">
                                <input class="input" placeholder="Oppure cerca cliente per ragione sociale" x-model="customerQuery"
                                       @input.debounce.250ms="searchCustomers()" @keydown.escape="customerResults = []">
                                <div x-show="customerResults.length" x-cloak @click.outside="customerResults = []"
                                     class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl bg-white shadow-xl ring-1 ring-stone-200">
                                    <template x-for="c in customerResults" :key="c.id">
                                        <button type="button" @click="selectCustomer(c)" class="block w-full border-t border-stone-100 px-4 py-2.5 text-left first:border-0 hover:bg-brand-50">
                                            <div class="font-semibold" x-text="c.name"></div>
                                            <div class="text-xs text-stone-500" x-text="`P.IVA ${c.vat_code} · ${c.city ?? ''}`"></div>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <button type="button" class="text-sm font-semibold text-brand-700" @click="$dispatch('customer:open', { customer: { vat_code: vat }, source: null })">+ Nuovo cliente a mano</button>
                        </div>
                    </template>
                    <template x-if="customer.id">
                        <div class="rounded-xl bg-brand-50 p-3 text-sm ring-1 ring-brand-100">
                            <div class="font-semibold" x-text="customer.name"></div>
                            <div class="text-stone-600" x-text="`P.IVA ${customer.vat_code}`"></div>
                            <div class="text-stone-600" x-text="`${customer.address ?? ''}, ${customer.postcode ?? ''} ${customer.city ?? ''} ${customer.province ?? ''}`"></div>
                            <div class="mt-1 text-xs" :class="(customer.sdi_code && customer.sdi_code !== '0000000') || customer.pec ? 'text-stone-600' : 'text-amber-700'"
                                 x-text="customer.sdi_code && customer.sdi_code !== '0000000' ? `SDI ${customer.sdi_code}` : (customer.pec ? `PEC ${customer.pec}` : 'SDI 0000000: la fattura andrà nel cassetto fiscale')"></div>
                            <div class="mt-2 flex gap-4">
                                <button type="button" class="text-sm font-semibold text-brand-700" @click="editCustomer()">Vedi e modifica dati</button>
                                <button type="button" class="text-sm font-semibold text-brand-700" @click="resetCustomer()">Cambia cliente</button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="card p-4">
                <div class="flex items-baseline justify-between">
                    <span class="text-stone-500">Totale</span>
                    <span class="text-4xl font-bold tabular-nums" x-text="euro(totalCents)"></span>
                </div>
                <p x-show="error" x-text="error" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700"></p>
                <div class="mt-4 grid gap-2">
                    <button type="button" class="btn-primary py-5 text-xl" :disabled="!canPay" @click="pay('contanti')">Contanti</button>
                    <button type="button" class="btn-light py-4" :disabled="!canPay" @click="pay('pos')">Carta / Bancomat (POS)</button>
                </div>
                <p class="mt-3 text-xs text-stone-500" x-show="documentType === 'fattura' && !customer.id">Per la fattura serve il cliente: cerca la partita IVA.</p>
            </div>
        </aside>

        {{-- Finestra incasso VNE --}}
        <div x-show="modal" x-cloak class="fixed inset-0 z-30 flex items-center justify-center bg-stone-900/50 p-4">
            <div class="card w-full max-w-md p-6 shadow-xl" @click.stop>
                <template x-if="modal === 'payment'">
                    <div>
                        <div class="text-sm font-semibold uppercase tracking-wide text-stone-500" x-text="payment.method === 'carta' ? 'Pagamento con carta sulla cassa' : 'Pagamento in contanti sulla cassa'"></div>
                        <div class="mt-1 text-4xl font-bold tabular-nums" x-text="euro(sale.total_cents)"></div>

                        <dl class="mt-5 grid grid-cols-2 gap-3 text-center">
                            <div class="rounded-xl bg-stone-100 p-3">
                                <dt class="text-xs text-stone-500">Inserito</dt>
                                <dd class="text-xl font-semibold tabular-nums" x-text="payment.details?.inserted === -2 ? 'carta' : euro(Math.max(0, payment.details?.inserted ?? 0))"></dd>
                            </div>
                            <div class="rounded-xl bg-stone-100 p-3">
                                <dt class="text-xs text-stone-500">Resto</dt>
                                <dd class="text-xl font-semibold tabular-nums" x-text="euro(payment.details?.rest ?? 0)"></dd>
                            </div>
                        </dl>

                        <div class="mt-5 rounded-xl p-3 text-center font-semibold"
                             :class="{
                                'bg-amber-50 text-amber-800': payment.state === 'pending',
                                'bg-red-50 text-red-700': ['notCompleted', 'error'].includes(payment.state),
                                'bg-stone-100 text-stone-700': ['cancelled', 'partial'].includes(payment.state),
                             }">
                            <span x-show="payment.state === 'pending'">In attesa del cliente alla cassa…</span>
                            <span x-show="payment.state === 'notCompleted'" x-text="`La cassa non riesce a dare tutto il resto: mancano ${euro(payment.not_paid)}. Fai un refill oppure annulla accettando il parziale.`"></span>
                            <span x-show="payment.state === 'error'" x-text="payment.message"></span>
                            <span x-show="payment.state === 'cancelled'">Pagamento annullato: la vendita è tornata in bozza.</span>
                            <span x-show="payment.state === 'partial'" x-text="`Pagamento parziale: incassati ${euro(payment.details?.inserted ?? 0)}. La vendita è tornata in bozza.`"></span>
                        </div>
                        <p x-show="cancelMessage" x-text="cancelMessage" class="mt-2 text-center text-sm text-stone-500"></p>

                        <div class="mt-5 grid gap-2" x-show="['pending', 'notCompleted', 'error'].includes(payment.state)">
                            <button type="button" class="btn-light" @click="cancelPayment(2)">Annulla e restituisci il denaro</button>
                            <button type="button" class="btn-light" @click="cancelPayment(1)">Annulla accettando il parziale</button>
                        </div>
                        <div class="mt-5" x-show="['cancelled', 'partial'].includes(payment.state)">
                            <button type="button" class="btn-primary w-full" @click="modal = null">Torna alla vendita</button>
                        </div>

                        @if ($vneDriver === 'fake')
                            <div class="mt-5 border-t border-dashed border-stone-300 pt-4" x-show="payment.state === 'pending'">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-amber-700">Cassa simulata · come va a finire?</div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($scenarios as $key => $label)
                                        <button type="button" class="rounded-full px-3 py-1.5 text-sm font-semibold ring-1"
                                                :class="scenario === '{{ $key }}' ? 'bg-amber-100 text-amber-900 ring-amber-300' : 'ring-stone-300 text-stone-600'"
                                                @click="setScenario('{{ $key }}')">{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </template>

                <template x-if="modal === 'done'">
                    <div class="text-center">
                        <template x-if="result?.loading">
                            <div class="py-8 text-lg font-semibold text-stone-500">Emissione documento…</div>
                        </template>
                        <template x-if="result?.type === 'scontrino'">
                            <div>
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 text-3xl text-brand-600">✓</div>
                                <div class="mt-3 text-xl font-bold">Scontrino emesso</div>
                                <div class="mt-1 text-stone-500" x-text="`Documento commerciale n. ${result.sale.receipt_number}`"></div>
                                <template x-if="result.previewUrl">
                                    <iframe :src="result.previewUrl" class="mt-4 h-72 w-full rounded-xl bg-stone-100 ring-1 ring-stone-200"></iframe>
                                </template>
                            </div>
                        </template>
                        <template x-if="result?.type === 'fattura'">
                            <div>
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full text-3xl" :class="result.ok ? 'bg-brand-50 text-brand-600' : 'bg-amber-50 text-amber-600'" x-text="result.ok ? '✓' : '!'"></div>
                                <div class="mt-3 text-xl font-bold" x-text="result.ok ? `Fattura n. ${result.sale.fattura24_doc_number} emessa` : 'Pagamento incassato, fattura non inviata'"></div>
                                <div class="mt-1 text-stone-500" x-text="result.message"></div>
                                <a :href="result.showUrl" class="mt-3 inline-block font-semibold text-brand-700">Apri la vendita</a>
                            </div>
                        </template>
                        <template x-if="result?.type === 'da_emettere'">
                            <div>
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 text-3xl text-amber-600">!</div>
                                <div class="mt-3 text-xl font-bold">Pagamento incassato, documento da emettere</div>
                                <div class="mt-1 text-stone-500" x-text="`${euro(sale.paid_cents || sale.total_cents)} già incassati: emetti ${documentType === 'fattura' ? 'la fattura' : 'lo scontrino'} per chiudere la vendita.`"></div>
                                <button type="button" class="btn-primary mt-5 w-full" @click="emitDocument()" x-text="documentType === 'fattura' ? 'Emetti fattura' : 'Emetti scontrino'"></button>
                                <div class="mt-3 flex justify-center gap-6 text-sm font-semibold">
                                    <a :href="`/vendite/${sale.id}`" class="text-brand-700">Apri la vendita</a>
                                    <button type="button" class="text-stone-500" @click="newSale()">Più tardi</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="result?.type === 'errore'">
                            <div>
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-3xl text-red-600">!</div>
                                <div class="mt-3 text-xl font-bold">Pagamento incassato, documento non emesso</div>
                                <div class="mt-1 text-stone-500" x-text="result.message"></div>
                                <div class="mt-4 flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm font-semibold">
                                    <button type="button" class="btn-primary px-6" @click="emitDocument()">Riprova</button>
                                    <a :href="result.showUrl" class="self-center text-brand-700">Apri la vendita</a>
                                </div>
                            </div>
                        </template>
                        <button type="button" class="btn-primary mt-6 w-full" x-show="!result?.loading && result?.type !== 'da_emettere'" @click="newSale()">Nuova vendita</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-layout>
