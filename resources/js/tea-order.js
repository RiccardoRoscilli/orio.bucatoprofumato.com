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
