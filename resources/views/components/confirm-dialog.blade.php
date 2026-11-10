{{-- Finestra di conferma condivisa: si apre con askConfirm() o con data-confirm sui form (resources/js/confirm.js) --}}
<div x-data x-show="$store.confirm.open" x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4"
     @click.self="$store.confirm.answer(false)"
     @keydown.escape.window="$store.confirm.open && $store.confirm.answer(false)">
    <div class="card w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <h2 id="confirm-title" class="text-lg font-bold" x-text="$store.confirm.title"></h2>
        <p class="mt-2 text-stone-600" x-show="$store.confirm.message" x-text="$store.confirm.message"></p>

        <dl class="mt-4 divide-y divide-stone-100 rounded-xl bg-stone-50 px-4" x-show="$store.confirm.items.length">
            <template x-for="item in $store.confirm.items" :key="item.label">
                <div class="flex items-baseline justify-between gap-4 py-2">
                    <dt class="text-sm text-stone-500" x-text="item.label"></dt>
                    <dd class="font-semibold tabular-nums" x-text="item.value"></dd>
                </div>
            </template>
        </dl>

        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200"
           x-show="$store.confirm.warning" x-text="$store.confirm.warning"></p>

        <div class="mt-6 grid grid-cols-2 gap-3">
            <button type="button" class="btn-light py-3" @click="$store.confirm.answer(false)">Annulla</button>
            <button type="button" class="py-3" :class="$store.confirm.danger ? 'btn-danger' : 'btn-primary'"
                    x-text="$store.confirm.confirmLabel" @click="$store.confirm.answer(true)" x-init="$watch('$store.confirm.open', (v) => v && $nextTick(() => $el.focus()))"></button>
        </div>
    </div>
</div>
