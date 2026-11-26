@props(['item'])
{{--
    Al posto della foto, per i cesti: icona che apre il popup col contenuto. È uno span e non un
    button perché sta anche dentro i risultati di ricerca, che sono già <button> (no annidati).
    `item` è l'espressione Alpine del prodotto (forma di Product::toCartItem()).
--}}
<span x-show="{{ $item }}.components?.length" x-cloak role="button" tabindex="0" title="Vedi il contenuto del cesto"
      @click.stop.prevent="$dispatch('basket-open', { name: {{ $item }}.description, components: {{ $item }}.components })"
      @keydown.enter.stop.prevent="$dispatch('basket-open', { name: {{ $item }}.description, components: {{ $item }}.components })"
      {{ $attributes->merge(['class' => 'flex shrink-0 cursor-pointer items-center justify-center rounded-lg bg-brand-50 text-brand-700 ring-1 ring-brand-200 hover:bg-brand-100']) }}>
    <x-basket-icon class="h-1/2 w-1/2" />
</span>
