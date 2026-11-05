import axios from 'axios';

const FIELDS = ['id', 'name', 'vat_code', 'fiscal_code', 'address', 'postcode', 'city', 'province', 'country', 'sdi_code', 'pec', 'email', 'phone'];

const blank = () => Object.fromEntries(FIELDS.map((f) => [f, f === 'country' ? 'IT' : '']));

/**
 * Finestra unica dei dati cliente, usata dalla cassa e dalla pagina Clienti.
 * Si apre con:  window.dispatchEvent(new CustomEvent('customer:open', { detail: { customer, source } }))
 * Al salvataggio emette 'customer:saved' con il cliente salvato.
 */
export default () => ({
    open: false,
    customer: blank(),
    source: null,
    errors: {},
    saving: false,

    get sdiMissing() {
        return !this.customer.sdi_code || this.customer.sdi_code === '0000000';
    },

    show({ customer = {}, source = null } = {}) {
        this.customer = { ...blank(), ...Object.fromEntries(Object.entries(customer).filter(([k, v]) => FIELDS.includes(k) && v !== null)) };
        this.source = source;
        this.errors = {};
        this.open = true;
        this.$nextTick(() => this.$refs.first?.focus());
    },

    close() {
        this.open = false;
    },

    async save() {
        this.saving = true;
        this.errors = {};
        try {
            const { data } = await axios.post('/clienti', this.customer);
            window.dispatchEvent(new CustomEvent('customer:saved', { detail: data.customer }));
            this.open = false;
        } catch (e) {
            this.errors = e.response?.data?.errors ?? { name: [e.response?.data?.message ?? 'Salvataggio non riuscito.'] };
        } finally {
            this.saving = false;
        }
    },
});
