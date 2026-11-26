import $ from 'jquery';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import { italian } from './datatables-it';

const euro = (cents) => cents === null ? '—' : (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const escape = (value) => $('<div>').text(value ?? '').html();
const ITALIAN = italian('prodotti', 'nome, SKU, EAN, categoria, formato');



// Icona cesto (Lucide "shopping-basket", ISC): al posto della foto, apre il popup del contenuto (x-basket-popup)
const BASKET_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5" aria-hidden="true"><path d="m15 11-1 9"/><path d="m19 11-4-7"/><path d="M2 11h20"/><path d="m3.5 11 1.6 7.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 2-1.6l1.7-7.4"/><path d="M4.5 15.5h15"/><path d="m5 11 4-7"/><path d="m9 11 1 9"/></svg>';

const el = document.getElementById('products-table');
const { dataUrl, showUrl, priceUrl, alignUrl, shopUrl, isAdmin } = el.dataset;
const productUrl = (id) => escape(showUrl.replace('__ID__', id));
const admin = isAdmin === '1';
let filter = el.dataset.initialFilter;

const table = new DataTable(el, {
    serverSide: true,
    processing: true,
    pageLength: 50,
    lengthMenu: [25, 50, 100, 200],
    order: [[1, 'asc']],
    searchDelay: 250,
    // Ricerca ripresa dall'indirizzo: ricaricando la pagina o tornando da una scheda resta quella
    search: { search: new URLSearchParams(location.search).get('q') ?? '' },
    language: ITALIAN,
    layout: {
        topStart: 'search',
        topEnd: 'pageLength',
        bottomStart: 'info',
        bottomEnd: 'paging',
    },
    ajax: {
        url: dataUrl,
        data: (d) => { d.filtro = filter; },
    },
    createdRow: (row, data) => { if (data.to_review) row.classList.add('to-review'); },
    columns: [
        {
            data: 'image_url', orderable: false, width: '56px',
            render: (url, type, p) => p.components?.length
                ? `<button type="button" data-basket title="Vedi il contenuto del cesto" class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-50 text-brand-700 ring-1 ring-brand-200 hover:bg-brand-100">${BASKET_ICON}</button>`
                : url ? `<a href="${productUrl(p.id)}" tabindex="-1"><img src="${escape(url)}" alt="" loading="lazy" class="h-10 w-10 rounded-lg object-cover ring-1 ring-stone-200"></a>` : '',
        },
        {
            data: 'name',
            render: (name, type, p) => {
                const meta = [p.format, p.category].filter(Boolean).map(escape).join(' · ');
                const flags = (!p.active ? ' · <span class="text-red-600">non attivo sul sito</span>' : '')
                    + (p.active && !p.sold_in_shop ? ' · <span class="text-stone-500">non venduto in negozio</span>' : '')
                    + (p.active && !p.in_stock ? ' · <span class="text-amber-700">esaurito sul sito</span>' : '');
                return `<a href="${productUrl(p.id)}" class="font-semibold hover:text-brand-700 hover:underline">${escape(name)}</a><div class="text-xs text-stone-500">${meta}${flags}</div>`;
            },
        },
        { data: 'sku', render: (v) => `<span class="text-xs">${escape(v)}</span>` },
        { data: 'ean', render: (v) => `<span class="font-mono text-xs">${escape(v)}</span>` },
        {
            data: 'sold_in_shop', className: 'dt-center', orderable: false,
            render: (sold, type, p) => {
                if (type !== 'display') return sold ? 1 : 0;
                if (!admin) return sold ? '<span class="text-brand-700">sì</span>' : '<span class="text-stone-400">no</span>';
                return `<label class="inline-flex cursor-pointer items-center gap-1.5"><input type="checkbox" data-shop="${p.id}" ${sold ? 'checked' : ''} class="h-5 w-5 accent-brand-600"><span class="text-xs text-stone-500">${sold ? 'sì' : 'no'}</span></label>`;
            },
        },
        {
            data: 'stock_qty', className: 'dt-right tabular-nums',
            render: (qty, type) => type !== 'display' ? qty
                : `<span class="${qty < 0 ? 'font-semibold text-red-600' : qty > 0 ? 'font-semibold' : 'text-stone-400'}">${(Math.round(qty * 1000) / 1000).toLocaleString('it-IT')}</span>`,
        },
        { data: 'site_price_cents', className: 'dt-right text-stone-500 tabular-nums', render: euro },
        {
            data: 'price_cents', className: 'dt-right',
            render: (cents, type, p) => {
                if (type !== 'display') return cents;
                if (!admin) return `<span class="font-semibold tabular-nums">${euro(cents)}</span>`;
                const value = (cents / 100).toFixed(2).replace('.', ',');
                return `<div class="flex items-center justify-end gap-1"><span class="text-stone-400">€</span>
                        <input data-price="${p.id}" value="${value}" inputmode="decimal" class="input w-24 py-1.5 text-right tabular-nums"></div>
                        <div class="price-msg text-xs"></div>`;
            },
        },
        {
            data: 'promo', className: 'dt-right whitespace-nowrap', orderable: false,
            render: (promo, type) => type !== 'display' ? (promo?.price_cents ?? '')
                : promo ? `<span class="font-bold tabular-nums text-brand-700">${euro(promo.price_cents)}</span><div class="text-xs text-stone-500">fino al ${promo.until}</div>`
                : '<span class="text-stone-300">—</span>',
        },
        {
            data: null, orderable: false, className: 'dt-right',
            render: (v, type, p) => admin && p.to_review
                ? `<button type="button" data-align="${p.id}" class="text-sm font-semibold text-brand-700 hover:underline">Usa prezzo sito</button>`
                : '',
        },
    ],
});

// Il campo di ricerca prende il focus all'apertura, come nella cassa
table.on('init', () => $(el).closest('.dt-container').find('.dt-search input').trigger('focus'));

// Filtro e ricerca sempre nell'indirizzo (?filtro=…&q=…), così sopravvivono al ricaricamento
function syncUrl() {
    const params = new URLSearchParams({ filtro: filter });
    const q = table.search().trim();
    if (q) params.set('q', q);
    history.replaceState(null, '', `?${params}`);
}
table.on('search', syncUrl);

// Filtri a pulsante (Attivi, Prezzi da rivedere, ...) senza ricaricare la pagina
document.querySelectorAll('button[data-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        filter = button.dataset.filter;
        document.querySelectorAll('button[data-filter]').forEach((b) => b.classList.toggle('is-active', b === button));
        syncUrl();
        table.ajax.reload();
    });
});

async function savePrice(input) {
    const msg = input.closest('td').querySelector('.price-msg');
    try {
        const { data } = await window.axios.patch(priceUrl.replace('__ID__', input.dataset.price), { price: input.value });
        msg.textContent = 'Salvato';
        msg.className = 'price-msg text-xs text-brand-700';
        input.closest('tr').classList.toggle('to-review', data.to_review);
    } catch (e) {
        msg.textContent = e.response?.data?.message ?? 'Errore';
        msg.className = 'price-msg text-xs text-red-600';
    }
}

$(el).on('change', 'input[data-price]', (e) => savePrice(e.target));
$(el).on('change', 'input[data-shop]', async (e) => {
    const input = e.target;
    const label = input.nextElementSibling;
    try {
        const { data } = await window.axios.patch(shopUrl.replace('__ID__', input.dataset.shop), { sold_in_shop: input.checked });
        input.checked = data.sold_in_shop;
        label.textContent = data.sold_in_shop ? 'sì' : 'no';
    } catch (err) {
        input.checked = !input.checked;
        label.textContent = 'errore';
    }
});
$(el).on('keydown', 'input[data-price]', (e) => { if (e.key === 'Enter') e.target.blur(); });
$(el).on('click', 'button[data-align]', async (e) => {
    await window.axios.post(alignUrl.replace('__ID__', e.target.dataset.align));
    table.ajax.reload(null, false);
});

// Sincronizzazione catalogo: puo' durare decine di secondi, quindi spinner e pulsante bloccato
const syncButton = document.getElementById('sync-button');
const syncResult = document.getElementById('sync-result');

function showSyncResult(message, ok) {
    syncResult.textContent = message;
    syncResult.className = `mb-4 rounded-xl px-4 py-3 text-sm ring-1 ${ok
        ? 'bg-brand-50 text-brand-700 ring-brand-100'
        : 'bg-red-50 text-red-700 ring-red-200'}`;
}

syncButton?.addEventListener('click', async () => {
    const label = syncButton.querySelector('[data-label]');
    const original = label.textContent;
    syncButton.disabled = true;
    syncButton.querySelector('[data-spinner]').classList.remove('hidden');
    label.textContent = 'Sincronizzazione in corso…';
    syncResult.classList.add('hidden');

    try {
        const { data } = await window.axios.post(syncButton.dataset.url, {}, { timeout: 300000 });
        showSyncResult(data.message, true);
        document.getElementById('last-sync').textContent = data.last_sync;
        table.ajax.reload(null, false);
    } catch (e) {
        showSyncResult(e.response?.data?.message ?? 'Sincronizzazione non riuscita.', false);
    } finally {
        syncButton.disabled = false;
        syncButton.querySelector('[data-spinner]').classList.add('hidden');
        label.textContent = original;
    }
});

$(el).on('click', '[data-basket]', function () {
    const p = table.row($(this).closest('tr')).data();
    window.dispatchEvent(new CustomEvent('basket-open', { detail: { name: p.name, components: p.components } }));
});
