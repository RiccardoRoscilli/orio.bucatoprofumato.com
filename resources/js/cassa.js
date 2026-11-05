import axios from 'axios';

const euro = (cents) => (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });

// crypto.randomUUID esiste solo in HTTPS: con orio in http (o sull'iPad in LAN) non c'e'
let rowSeq = 0;
const nextKey = () => `r${++rowSeq}`;

const parsePrice = (value) => parseFloat(String(value).replace(',', '.')) || 0;

const sounds = {
    success: new Audio('/sounds/success.mp3'),
    error: new Audio('/sounds/error.mp3'),
};

const beep = (type) => {
    const sound = sounds[type];
    sound.currentTime = 0;
    sound.play().catch(() => {});
};

const emptyCustomer = () => ({
    id: null, name: '', vat_code: '', fiscal_code: '', address: '', postcode: '',
    city: '', province: '', sdi_code: '', pec: '', email: '',
});

export default () => ({
    rows: [],

    // Lettore codici a barre / ricerca
    scan: '',
    pendingQty: 1,
    qtyModal: false,
    qtyInput: '',
    results: [],
    resultsTitle: null,
    scanMessage: null,
    searchTimer: null,
    flashKey: null,
    documentType: 'scontrino',
    vat: '',
    customer: emptyCustomer(),
    customerSource: null,
    customerQuery: '',
    customerResults: [],
    customerError: null,
    lookingUp: false,
    error: null,
    busy: false,

    // Finestra di incasso / esito
    modal: null, // null | 'payment' | 'done'
    sale: null,
    payment: null,
    scenario: 'completed',
    cancelMessage: null,
    result: null,
    pollTimer: null,

    euro,

    get totalCents() {
        return this.rows.reduce((sum, r) => sum + this.lineCents(r), 0);
    },

    get totalPieces() {
        return this.rows.reduce((sum, r) => sum + (parseFloat(r.qty) || 0), 0);
    },

    get validRows() {
        return this.rows.filter((r) => r.description.trim() && parsePrice(r.price) > 0 && parseFloat(r.qty) > 0);
    },

    get canPay() {
        return this.validRows.length > 0 && this.totalCents > 0 && !this.busy
            && (this.documentType === 'scontrino' || this.customer.id);
    },

    init() {
        this.focusScan();
    },

    focusScan() {
        this.$nextTick(() => this.$refs.scan?.focus());
    },

    // Invio dal lettore (o da tastiera): codice numerico = bip, altrimenti ricerca per nome
    // I bip vengono elaborati in coda: un lettore veloce puo' mandare il codice successivo
    // prima che la risposta del precedente sia arrivata.
    scanQueue: Promise.resolve(),

    submitScan() {
        const code = this.scan.trim();
        clearTimeout(this.searchTimer);
        if (!code) return;

        if (/^\d{6,14}$/.test(code)) {
            this.scan = '';
            this.scanQueue = this.scanQueue.then(() => this.lookupBarcode(code)).catch(() => {});
        } else {
            this.scanQueue = this.scanQueue.then(() => this.search(code, true)).catch(() => {});
        }
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

    async lookupBarcode(code) {
        this.scanMessage = null;
        try {
            const { data } = await axios.get(`/prodotti/barcode/${encodeURIComponent(code)}`);
            if (data.products.length === 1) {
                this.addProduct(data.products[0]);
            } else {
                beep('success');
                this.results = data.products;
                this.resultsTitle = `Più prodotti con il codice ${code}: scegli quale`;
            }
        } catch (e) {
            beep('error');
            this.scanMessage = firstError(e);
            this.results = [];
        }
    },

    async search(q, fromEnter) {
        const { data } = await axios.get('/prodotti/cerca', { params: { q } });
        this.results = data.products;
        this.resultsTitle = data.products.length ? null : 'Nessun prodotto trovato';
        if (fromEnter && data.products.length === 1) {
            if (this.scan.trim() === q) this.scan = '';
            this.addProduct(data.products[0], false, false);
        } else if (fromEnter && !data.products.length) {
            beep('error');
        }
    },

    addProduct(product, silent = false, clearScan = true) {
        const qty = this.pendingQty;
        const existing = this.rows.find((r) => r.product_id === product.product_id && r.price === product.price);

        if (existing) {
            existing.qty = (parseFloat(existing.qty) || 0) + qty;
            this.flash(existing.key);
        } else {
            const row = { ...product, key: nextKey(), qty };
            this.rows.unshift(row);
            this.flash(row.key);
        }

        if (!silent) beep('success');
        this.pendingQty = 1;
        if (clearScan && !/^\d{6,14}$/.test(this.scan.trim())) this.scan = '';
        this.results = [];
        this.resultsTitle = null;
        this.scanMessage = null;
        this.focusScan();
    },

    flash(key) {
        this.flashKey = key;
        setTimeout(() => { if (this.flashKey === key) this.flashKey = null; }, 900);
    },

    openQty() {
        this.qtyInput = '';
        this.qtyModal = true;
    },

    setPendingQty(value) {
        const qty = parseInt(value, 10);
        if (qty > 0) this.pendingQty = qty;
        this.qtyModal = false;
        this.focusScan();
    },

    changeQty(row, delta) {
        row.qty = Math.max(1, (parseFloat(row.qty) || 0) + delta);
    },

    removeRow(index) {
        this.rows.splice(index, 1);
        this.focusScan();
    },

    lineCents(row) {
        return Math.round((parseFloat(row.qty) || 0) * Math.round(parsePrice(row.price) * 100));
    },

    // Ricerca per partita IVA: anagrafica, poi OpenAPI (lato server). In ogni caso si apre la
    // finestra cliente con tutti i dati da controllare; se il cliente esiste e' gia' selezionato.
    async lookupVat() {
        this.customerError = null;
        this.lookingUp = true;
        try {
            const { data } = await axios.get(`/clienti/piva/${encodeURIComponent(this.vat)}`);
            this.selectCustomer(data.customer, data.source);
            this.editCustomer();
        } catch (e) {
            if (e.response?.status === 422) {
                this.customerError = e.response.data.error;
            } else {
                this.customerError = e.response?.data?.error ?? 'Ricerca non riuscita.';
                this.customerSource = 'manuale';
                window.dispatchEvent(new CustomEvent('customer:open', {
                    detail: { customer: e.response?.data?.customer ?? { vat_code: this.vat }, source: 'manuale' },
                }));
            }
        } finally {
            this.lookingUp = false;
        }
    },

    async searchCustomers() {
        const q = this.customerQuery.trim();
        if (q.length < 2) {
            this.customerResults = [];
            return;
        }
        const { data } = await axios.get('/clienti/cerca', { params: { q } });
        this.customerResults = data.customers;
    },

    selectCustomer(customer, source = 'anagrafica') {
        this.customer = { ...emptyCustomer(), ...customer };
        this.customerSource = source;
        this.customerQuery = '';
        this.customerResults = [];
        this.customerError = null;
    },

    editCustomer() {
        window.dispatchEvent(new CustomEvent('customer:open', { detail: { customer: this.customer, source: this.customerSource } }));
    },

    // Salvataggio dalla finestra cliente
    onCustomerSaved(customer) {
        this.selectCustomer(customer, 'anagrafica');
    },

    resetCustomer() {
        this.customer = emptyCustomer();
        this.customerSource = null;
        this.customerQuery = '';
        this.customerResults = [];
        this.vat = '';
    },

    async pay(method) {
        this.error = null;
        this.busy = true;
        try {
            const { data } = await axios.post('/vendite', {
                document_type: this.documentType,
                customer_id: this.customer.id,
                items: this.validRows.map((r) => ({
                    product_id: r.product_id,
                    sku: r.sku,
                    description: r.description,
                    qty: r.qty,
                    price: String(r.price).replace(',', '.'),
                    vat_rate: r.vat_rate,
                })),
            });
            this.sale = data.sale;

            const start = await axios.post(`/vendite/${this.sale.id}/pagamento`, { method });
            if (start.data.state === 'completed') {
                await this.emitDocument();
                return;
            }

            this.payment = { state: 'pending', method, details: { amount: this.sale.total_cents, inserted: 0, rest: 0 } };
            this.scenario = 'completed';
            this.cancelMessage = null;
            this.modal = 'payment';
            this.poll();
        } catch (e) {
            this.error = firstError(e);
            this.busy = false;
        }
    },

    poll() {
        clearTimeout(this.pollTimer);
        this.pollTimer = setTimeout(async () => {
            try {
                const { data } = await axios.get(`/vendite/${this.sale.id}/pagamento`);
                this.payment = { ...this.payment, ...data };

                if (data.state === 'completed') {
                    await this.emitDocument();
                    return;
                }
                if (['cancelled', 'partial'].includes(data.state)) {
                    this.busy = false;
                    return;
                }
            } catch (e) {
                this.payment.state = 'error';
                this.payment.message = firstError(e);
            }
            this.poll();
        }, 1000);
    },

    async setScenario(value) {
        this.scenario = value;
        await axios.post(`/vendite/${this.sale.id}/pagamento/scenario`, { scenario: value });
    },

    async cancelPayment(mode) {
        try {
            const { data } = await axios.post(`/vendite/${this.sale.id}/pagamento/annulla`, { mode });
            this.cancelMessage = data.message;
        } catch (e) {
            this.cancelMessage = firstError(e);
        }
    },

    async emitDocument() {
        clearTimeout(this.pollTimer);
        this.modal = 'done';
        this.result = { loading: true };

        try {
            if (this.documentType === 'scontrino') {
                const { data } = await axios.post(`/vendite/${this.sale.id}/scontrino`);
                if (data.driver === 'epson') {
                    await this.printOnEpson(data);
                } else {
                    this.result = { type: 'scontrino', sale: data.sale, previewUrl: data.preview_url };
                }
            } else {
                const res = await axios.post(`/vendite/${this.sale.id}/fattura`);
                this.result = { type: 'fattura', ok: res.data.success, message: res.data.message, sale: res.data.sale, showUrl: res.data.show_url };
            }
        } catch (e) {
            this.result = { type: 'errore', message: firstError(e), showUrl: `/vendite/${this.sale.id}` };
        }
        this.busy = false;
    },

    // Stampa dal browser della postazione: FPMate deve essere raggiungibile in HTTPS con
    // certificato attendibile (vedi docs/SPECIFICHE.md §4 e §7).
    async printOnEpson({ url, xml }) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'text/xml;charset=utf-8' }, body: xml });
        const text = await res.text();
        const doc = new DOMParser().parseFromString(text, 'text/xml');
        const response = doc.getElementsByTagName('response')[0];
        if (!response || response.getAttribute('success') !== 'true') {
            throw new Error('La stampante ha rifiutato lo scontrino: ' + (response?.getAttribute('status') ?? text.slice(0, 120)));
        }
        const number = doc.getElementsByTagName('fiscalReceiptNumber')[0]?.textContent ?? '?';
        const { data } = await axios.post(`/vendite/${this.sale.id}/scontrino/conferma`, { receipt_number: number });
        this.result = { type: 'scontrino', sale: data.sale };
    },

    newSale() {
        clearTimeout(this.pollTimer);
        this.rows = [];
        this.scan = '';
        this.results = [];
        this.pendingQty = 1;
        this.documentType = 'scontrino';
        this.resetCustomer();
        this.modal = null;
        this.sale = null;
        this.payment = null;
        this.result = null;
        this.error = null;
        this.busy = false;
        this.focusScan();
    },
});

function firstError(e) {
    const data = e.response?.data;
    if (data?.errors) return Object.values(data.errors)[0][0];
    return data?.message ?? e.message ?? 'Errore imprevisto.';
}
