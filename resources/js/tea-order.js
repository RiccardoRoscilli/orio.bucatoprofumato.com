import axios from 'axios';

const fmtQty = (qty) => (Math.round((qty ?? 0) * 1000) / 1000).toLocaleString('it-IT');

/** Ordine a TEA: proposta dei prodotti sotto scorta, modificabile, e invio a teaerp. */
export default (proposal = []) => ({
    rows: proposal.map((r) => ({ ...r })),
    query: '',
    results: [],
    searchTimer: null,
    notes: '',
    busy: false,
    error: null,
    importing: false,
    importInfo: null,
    importProblems: [],
    fmtQty,

    get totalPieces() {
        return this.rows.reduce((sum, r) => sum + (parseInt(r.qty, 10) || 0), 0);
    },

    onSearch() {
        clearTimeout(this.searchTimer);
        const q = this.query.trim();
        if (q.length < 2) { this.results = []; return; }
        this.searchTimer = setTimeout(async () => {
            const { data } = await axios.get('/prodotti/cerca', { params: { q } });
            this.results = data.products;
        }, 250);
    },

    add(product) {
        const existing = this.rows.find((r) => r.product_id === product.product_id);
        if (existing) existing.qty = (parseInt(existing.qty, 10) || 0) + 1;
        else this.rows.push({ product_id: product.product_id, sku: product.sku, name: product.description, format: product.format, stock: product.stock, stock_min: null, qty: 1 });
        this.query = '';
        this.results = [];
    },

    // Modello Excel compilato (scatole, come emmycasa): le sue righe diventano l'ordine
    async importExcel(event) {
        const file = event.target.files[0];
        event.target.value = '';
        if (!file) return;
        this.error = null;
        this.importInfo = null;
        this.importProblems = [];
        this.importing = true;
        try {
            const form = new FormData();
            form.append('file', file);
            const { data } = await axios.post('/ordini-tea/excel', form);
            this.importProblems = data.problems;
            if (!data.rows.length) {
                this.error = 'Nessuna riga del file è entrata nell\'ordine: vedi i motivi qui sotto.';
                return;
            }
            if (this.rows.length && !await window.askConfirm({
                title: 'Sostituire l\'ordine con il file?',
                message: 'I prodotti già nella tabella vengono tolti: l\'ordine diventa quello del file.',
                items: [{ label: 'Nel file', value: `${data.rows.length} prodotti, ${data.boxes} scatole, ${fmtQty(data.pieces)} pz` }],
                confirmLabel: 'Sostituisci',
            })) return;
            this.rows = data.rows;
            this.importInfo = `Dal file: ${data.rows.length} prodotti, ${data.boxes} scatole (${fmtQty(data.pieces)} pz). Controlla e poi invia.`;
        } catch (e) {
            const data = e.response?.data;
            this.error = data?.errors ? Object.values(data.errors)[0][0] : (data?.message ?? e.message);
        } finally {
            this.importing = false;
        }
    },

    boxesNote(row) {
        const qty = parseInt(row.qty, 10) || 0;
        if (!row.pcs_per_box || !qty) return '';
        return qty % row.pcs_per_box === 0
            ? `${qty / row.pcs_per_box} ${qty / row.pcs_per_box === 1 ? 'scatola' : 'scatole'} da ${row.pcs_per_box}`
            : `scatola da ${row.pcs_per_box}: non è un multiplo`;
    },

    async send() {
        this.error = null;
        const items = this.rows.filter((r) => parseInt(r.qty, 10) > 0).map((r) => ({ product_id: r.product_id, qty: parseInt(r.qty, 10) }));
        if (!items.length) { this.error = 'Aggiungi almeno un prodotto con una quantità.'; return; }
        if (!await window.askConfirm({
            title: 'Inviare l\'ordine a TEA?',
            items: [{ label: 'Prodotti', value: items.length }, { label: 'Pezzi', value: fmtQty(this.totalPieces) }],
            message: 'L\'ordine entra in lavorazione in teaerp con i prezzi del listino d\'acquisto, ritiro in sede.',
            confirmLabel: 'Invia a TEA',
        })) return;

        this.busy = true;
        try {
            const { data } = await axios.post('/ordini-tea', { items, notes: this.notes || null });
            window.location = data.show_url;
        } catch (e) {
            const data = e.response?.data;
            this.error = data?.errors ? Object.values(data.errors)[0][0] : (data?.message ?? e.message);
            if (data?.show_url) setTimeout(() => { window.location = data.show_url; }, 2500);
            this.busy = false;
        }
    },
});
