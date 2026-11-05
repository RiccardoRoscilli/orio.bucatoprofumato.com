<x-layout title="Clienti">
    @vite('resources/js/customers.js')

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="mr-auto text-xl font-bold">Clienti</h1>
        <span class="text-sm text-stone-500">Clicca su un cliente per vedere e modificare tutti i dati.</span>
        <button type="button" class="btn-primary py-2" x-data @click="$dispatch('customer:open', { customer: {} })">+ Nuovo cliente</button>
    </div>

    <div class="card p-4">
        <table id="customers-table" class="products-table w-full text-sm" data-data-url="{{ route('customers.data') }}">
            <thead>
                <tr>
                    <th>Ragione sociale</th>
                    <th>P.IVA</th>
                    <th>Città</th>
                    <th>SDI</th>
                    <th class="dt-right">Vendite</th>
                </tr>
            </thead>
        </table>
    </div>
</x-layout>
