import $ from 'jquery';
import DataTable from 'datatables.net-dt';
import 'datatables.net-dt/css/dataTables.dataTables.css';
import { italian } from './datatables-it';

const escape = (value) => $('<div>').text(value ?? '').html();
const el = document.getElementById('customers-table');

const table = new DataTable(el, {
    serverSide: true,
    processing: true,
    pageLength: 50,
    lengthMenu: [25, 50, 100, 200],
    order: [[0, 'asc']],
    searchDelay: 250,
    language: italian('clienti', 'ragione sociale, P.IVA, CF, città, SDI, PEC'),
    layout: { topStart: 'search', topEnd: 'pageLength', bottomStart: 'info', bottomEnd: 'paging' },
    ajax: { url: el.dataset.dataUrl },
    createdRow: (row) => row.classList.add('cursor-pointer', 'hover:bg-stone-50'),
    columns: [
        {
            data: 'name',
            render: (name, type, c) => `<span class="font-semibold text-brand-700">${escape(name)}</span>`
                + (c.pec ? `<div class="text-xs text-stone-500">${escape(c.pec)}</div>` : ''),
        },
        {
            data: 'vat_code',
            render: (vat, type, c) => `<span class="font-mono text-xs">${escape(vat)}</span>`
                + (c.fiscal_code && c.fiscal_code !== vat ? `<div class="font-mono text-xs text-stone-500">CF ${escape(c.fiscal_code)}</div>` : ''),
        },
        { data: 'city', render: escape },
        {
            data: 'sdi_code',
            render: (sdi, type, c) => {
                if (sdi && sdi !== '0000000') return `<span class="font-mono text-xs">${escape(sdi)}</span>`;
                if (c.pec) return '<span class="badge bg-sky-100 text-sky-800">via PEC</span>';
                return '<span class="font-mono text-xs">0000000</span> <span class="badge bg-amber-100 text-amber-800">cassetto fiscale</span>';
            },
        },
        { data: 'sales_count', className: 'dt-right tabular-nums' },
    ],
});

table.on('init', () => $(el).closest('.dt-container').find('.dt-search input').trigger('focus'));

// Clic su un cliente: si apre la finestra con tutti i dati modificabili
$(el).on('click', 'tbody tr', function () {
    const row = table.row(this).data();
    if (row) window.dispatchEvent(new CustomEvent('customer:open', { detail: { customer: row.customer } }));
});

window.addEventListener('customer:saved', () => table.ajax.reload(null, false));
