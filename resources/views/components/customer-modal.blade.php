{{-- Dati cliente modificabili: vedi resources/js/customer-modal.js --}}
<div x-data="customerModal" @customer:open.window="show($event.detail)" @keydown.escape.window="close()"
     x-show="open" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/50 p-3 sm:p-4">
    {{-- Intestazione e pulsanti fissi, campi scorrevoli: sull'iPad la tastiera toglie meta' schermo --}}
    <form @submit.prevent="save()" @click.outside="close()"
          class="card flex max-h-[calc(100dvh-1.5rem)] w-full max-w-4xl flex-col shadow-xl">
        <div class="flex items-start justify-between gap-4 border-b border-stone-100 px-5 py-3">
            <div>
                <h2 class="text-lg font-bold" x-text="customer.id ? 'Dati cliente' : 'Nuovo cliente'"></h2>
                <p class="text-xs text-stone-500" x-show="source === 'openapi'">Dati trovati con OpenAPI e già salvati in anagrafica: controlla e completa.</p>
                <p class="text-xs text-stone-500" x-show="source === 'anagrafica'">Cliente già presente in anagrafica.</p>
                <p class="text-xs text-amber-700" x-show="source === 'manuale'">Partita IVA non trovata: inserisci i dati a mano.</p>
            </div>
            <button type="button" @click="close()" class="text-2xl leading-none text-stone-400 hover:text-stone-700" aria-label="Chiudi">×</button>
        </div>

        @php
            $err = fn ($field) => "<p class=\"mt-1 text-xs text-red-600\" x-show=\"errors.{$field}\" x-text=\"errors.{$field}?.[0]\"></p>";
            $label = 'mb-1 block text-xs font-semibold text-stone-600';
        @endphp

        <div class="grid grid-cols-12 gap-x-3 gap-y-3 overflow-y-auto px-5 py-4">
            <div class="col-span-12 md:col-span-6">
                <label class="{{ $label }}">Ragione sociale</label>
                <input x-ref="first" x-model="customer.name" class="input py-2" required>
                {!! $err('name') !!}
            </div>
            <div class="col-span-6 md:col-span-3">
                <label class="{{ $label }}">Partita IVA</label>
                <input x-model="customer.vat_code" class="input py-2 font-mono" inputmode="numeric" maxlength="11" required>
                {!! $err('vat_code') !!}
            </div>
            <div class="col-span-6 md:col-span-3">
                <label class="{{ $label }}">Codice fiscale</label>
                <input x-model="customer.fiscal_code" class="input py-2 font-mono uppercase" maxlength="16">
                {!! $err('fiscal_code') !!}
            </div>

            <div class="col-span-12 md:col-span-5">
                <label class="{{ $label }}">Indirizzo</label>
                <input x-model="customer.address" class="input py-2" required>
                {!! $err('address') !!}
            </div>
            <div class="col-span-3 md:col-span-2">
                <label class="{{ $label }}">CAP</label>
                <input x-model="customer.postcode" class="input py-2" inputmode="numeric" required>
                {!! $err('postcode') !!}
            </div>
            <div class="col-span-5 md:col-span-3">
                <label class="{{ $label }}">Città</label>
                <input x-model="customer.city" class="input py-2" required>
                {!! $err('city') !!}
            </div>
            <div class="col-span-2 md:col-span-1">
                <label class="{{ $label }}">Prov.</label>
                <input x-model="customer.province" class="input px-2 py-2 uppercase" maxlength="2">
            </div>
            <div class="col-span-2 md:col-span-1">
                <label class="{{ $label }}">Naz.</label>
                <input x-model="customer.country" class="input px-2 py-2 uppercase" maxlength="2">
            </div>

            <div class="col-span-12 grid grid-cols-12 gap-x-3 gap-y-2 rounded-xl bg-stone-50 p-3 ring-1 ring-stone-200">
                <div class="col-span-12 text-xs font-bold uppercase tracking-wide text-stone-500">Fatturazione elettronica</div>
                <div class="col-span-4 md:col-span-3">
                    <label class="{{ $label }}">Codice SDI</label>
                    <input x-model="customer.sdi_code" class="input py-2 font-mono uppercase" maxlength="7" placeholder="7 caratteri">
                    {!! $err('sdi_code') !!}
                </div>
                <div class="col-span-8 md:col-span-5">
                    <label class="{{ $label }}">PEC</label>
                    <input x-model="customer.pec" type="email" class="input py-2">
                    {!! $err('pec') !!}
                </div>
                <p class="col-span-12 self-center text-xs text-amber-700 md:col-span-4" x-show="sdiMissing && !customer.pec">
                    Con SDI 0000000 e senza PEC la fattura è valida ma il cliente la trova solo nel cassetto fiscale.
                </p>
            </div>

            <div class="col-span-12 md:col-span-6">
                <label class="{{ $label }}">Email</label>
                <input x-model="customer.email" type="email" class="input py-2">
                {!! $err('email') !!}
            </div>
            <div class="col-span-12 md:col-span-6">
                <label class="{{ $label }}">Telefono</label>
                <input x-model="customer.phone" type="tel" class="input py-2">
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-stone-100 px-5 py-3">
            <button type="button" class="btn-light py-2.5" @click="close()">Annulla</button>
            <button class="btn-primary py-2.5" :disabled="saving" x-text="saving ? 'Salvataggio…' : 'Salva cliente'"></button>
        </div>
    </form>
</div>
