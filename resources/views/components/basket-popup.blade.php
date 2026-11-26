{{-- Contenuto di un cesto: si apre da ovunque con $dispatch('basket-open', { name, components }) (vedi x-basket-button) --}}
<div data-basket-popup x-data="{ basket: null }" x-show="basket" x-cloak
     @basket-open.window="basket = $event.detail"
     @keydown.escape.window="basket = null"
     @click.self="basket = null"
     class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/50 p-4">
    <div class="card w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 ring-1 ring-brand-200">
                <x-basket-icon class="h-6 w-6" />
            </span>
            <div class="min-w-0 flex-1">
                <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">Contenuto del cesto</div>
                <h2 class="font-bold leading-tight" x-text="basket?.name"></h2>
            </div>
        </div>
        <ul class="mt-4 divide-y divide-stone-100 rounded-xl bg-stone-50 px-4 text-sm">
            <template x-for="(c, i) in basket?.components ?? []" :key="i">
                <li class="flex items-baseline gap-3 py-2">
                    <span class="w-10 shrink-0 text-right font-bold tabular-nums" x-text="`${String(+c.qty).replace('.', ',')} ×`"></span>
                    <span x-text="c.name"></span>
                </li>
            </template>
        </ul>
        <button type="button" class="btn-light mt-5 w-full py-3" @click="basket = null">Chiudi</button>
    </div>
</div>
