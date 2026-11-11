import axios from 'axios';

// Carico merce e inventario del magazzino del negozio, col lettore di codici a barre come in cassa
const sounds = { success: new Audio('/sounds/success.mp3'), error: new Audio('/sounds/error.mp3') };
const beep = (type) => {
    sounds[type].currentTime = 0;
    sounds[type].play().catch(() => {});
};

const fmtQty = (qty) => (Math.round(qty * 1000) / 1000).toLocaleString('it-IT');

function firstError(e) {
    const data = e.response?.data;
    if (data?.errors) return Object.values(data.errors)[0][0];
    return data?.message ?? e.message ?? 'Errore imprevisto.';
}

export default (mode = 'carico') => ({
    mode,
    scan: '',
    results: [],
    resultsTitle: null,
    message: null,
    error: null,
    busy: false,
    fmtQty,

    // Carico: righe da registrare insieme
    rows: [],
    note: '',

    // Inventario: prodotto in conteggio e registro della sessione
    current: null,
    counted: '',
    log: [],

    init() {
        try {
            this.message = sessionStorage.getItem('orio-stock-message');
            sessionStorage.removeItem('orio-stock-message');
        } catch (e) { /* storage non disponibile */ }
        this.focusScan();
    },

    focusScan() {
        this.$nextTick(() => this.$refs.scan?.focus());
    },

    onScanInput() {
        clearTimeout(this.searchTimer);
        const q = this.scan.trim();
        if (q.length < 3 || /^\d+$/.test(q)) {
            this.results = [];
            return;
        }
        this.searchTimer = setTimeout(() => this.search(q, false), 250);
    },

    async submitScan() {
        const code = this.scan.trim();
        clearTimeout(this.searchTimer);
        if (!code) return;

        if (/^\d{6,14}$/.test(code)) {
            this.scan = '';
            try {
                const { data } = await axios.get(`/prodotti/barcode/${encodeURIComponent(code)}`);
                if (data.products.length === 1) {
                    this.pick(data.products[0]);
                } else {
                    this.results = data.products;
                    this.resultsTitle = `Più prodotti con il codice ${code}: scegli quale`;
                }
            } catch (e) {
                beep('error');
                this.error = firstError(e);
            }
        } else {
            await this.search(code, true);
        }
    },

    async search(q, fromEnter) {
        const { data } = await axios.get('/prodotti/cerca', { params: { q } });
        this.results = data.products;
        this.resultsTitle = data.products.length ? null : 'Nessun prodotto trovato';
        if (fromEnter && data.products.length === 1) this.pick(data.products[0]);
        else if (fromEnter && !data.products.length) beep('error');
    },

    pick(product) {
        beep('success');
        this.scan = '';
        this.results = [];
        this.resultsTitle = null;
        this.error = null;

        if (this.mode === 'inventario') {
            this.current = product;
            this.counted = '';
            this.$nextTick(() => this.$refs.counted?.focus());
            return;
        }

        const existing = this.rows.find((r) => r.product_id === product.product_id);
        if (existing) {
            existing.qty = (parseFloat(existing.qty) || 0) + 1;
            this.rows = [existing, ...this.rows.filter((r) => r !== existing)];
        } else {
            this.rows.unshift({ ...product, qty: 1 });
        }
        this.focusScan();
    },

    changeQty(row, delta) {
        row.qty = Math.max(1, (parseFloat(row.qty) || 0) + delta);
    },

    get totalPieces() {
        return this.rows.reduce((sum, r) => sum + (parseFloat(r.qty) || 0), 0);
    },

    async saveLoad() {
        if (!this.rows.length || this.busy) return;
        this.busy = true;
        this.error = null;
        try {
            const { data } = await axios.post('/magazzino/carico', {
                items: this.rows.map((r) => ({ product_id: r.product_id, qty: String(r.qty).replace(',', '.') })),
                note: this.note || null,
            });
            try { sessionStorage.setItem('orio-stock-message', data.message); } catch (e) { /* ignora */ }
            window.location.reload();
        } catch (e) {
            this.error = firstError(e);
            this.busy = false;
        }
    },

    async saveCount() {
        if (!this.current || this.counted === '' || this.busy) return;
        this.busy = true;
        this.error = null;
        try {
            const { data } = await axios.post('/magazzino/inventario', {
                product_id: this.current.product_id,
                counted: String(this.counted).replace(',', '.'),
            });
            this.log.unshift({ key: Date.now(), text: data.message });
            this.current = null;
            this.counted = '';
            this.focusScan();
        } catch (e) {
            this.error = firstError(e);
        }
        this.busy = false;
    },
});
