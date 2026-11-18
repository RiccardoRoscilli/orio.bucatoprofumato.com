import axios from 'axios';

const fmtQty = (qty) => (Math.round((qty ?? 0) * 1000) / 1000).toLocaleString('it-IT');

/**
 * Ordine a TEA a scatole intere (TEA non accetta pezzi sfusi): proposta dei prodotti sotto scorta,
 * modificabile, e invio a teaerp. I pezzi sono scatole x pezzi per scatola.
 */
export default (proposal = [], boxes = {}, open = null, suggestions = []) => ({
    rows: proposal.map((r) => ({ ...r })),
    perBox: boxes,
    suggestions,
    // Ordine in preparazione salvato sul server: resta aperto finche' non viene inviato
    saveState: open ? 'saved' : 'new',
    savedAt: open?.saved_at ?? null,
    savedBy: open?.by ?? null,
    saveTimer: null,
    query: '',
    results: [],
    searchTimer: null,
    notes: open?.notes ?? '',
    busy: false,
    error: null,
    importing: false,
    importInfo: null,
    importProblems: [],
    fmtQty,

    pieces(row) {
        return (parseInt(row.boxes, 10) || 0) * row.pcs_per_box;
    },

    init() {
        this.$watch('rows', () => this.queueSave());
        this.$watch('notes', () => this.queueSave());
    },

    get saveLabel() {
        return {
            new: this.rows.length ? 'Proposti i prodotti sotto scorta: modificali e l\'ordine si salva' : '',
            saving: 'Salvataggio…',
            error: 'Non salvato: controlla la connessione',
            saved: this.savedAt ? `Salvato ${this.savedAt}${this.savedBy ? ' · ' + this.savedBy : ''}` : 'Nessun ordine aperto',
        }[this.saveState];
    },

    queueSave() {
        if (this.busy) return;
        clearTimeout(this.saveTimer);
        this.saveState = 'saving';
        this.saveTimer = setTimeout(() => this.saveOpen(), 700);
    },

    async saveOpen() {
        try {
            const items = this.rows.map((r) => ({ product_id: r.product_id, boxes: Math.max(0, parseInt(r.boxes, 10) || 0) }));
            const { data } = await axios.put('/ordini-tea/in-preparazione', { items, notes: this.notes || null });
            this.savedAt = data.saved_at;
            this.savedBy = data.by;
            this.saveState = 'saved';
        } catch {
            this.saveState = 'error';
        }
    },

    get totalBoxes() {
        return this.rows.reduce((sum, r) => sum + (parseInt(r.boxes, 10) || 0), 0);
    },

    get totalPieces() {
        return this.rows.reduce((sum, r) => sum + this.pieces(r), 0);
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
        this.suggestions = this.suggestions.filter((s) => s.product_id !== product.product_id);
        if (existing) existing.boxes = (parseInt(existing.boxes, 10) || 0) + 1;
        else this.rows.push({
            product_id: product.product_id, sku: product.sku, name: product.description, format: product.format,
            stock: product.stock, stock_min: product.stock_min ?? null,
            pcs_per_box: this.perBox[(product.sku ?? '').trim()] ?? 1, boxes: 1,
        });
        this.query = '';
        this.results = [];
    },

    // Modello Excel compilato (a scatole): le sue righe diventano l'ordine
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

    async send() {
        this.error = null;
        const items = this.rows.filter((r) => parseInt(r.boxes, 10) > 0).map((r) => ({ product_id: r.product_id, boxes: parseInt(r.boxes, 10) }));
        if (!items.length) { this.error = 'Aggiungi almeno un prodotto con almeno una scatola.'; return; }
        if (!await window.askConfirm({
            title: 'Inviare l\'ordine a TEA?',
            items: [{ label: 'Prodotti', value: items.length }, { label: 'Scatole', value: this.totalBoxes }, { label: 'Pezzi', value: fmtQty(this.totalPieces) }],
            message: 'L\'ordine entra in lavorazione in teaerp con i prezzi del listino d\'acquisto, ritiro in sede.',
            confirmLabel: 'Invia a TEA',
        })) return;

        clearTimeout(this.saveTimer);
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
