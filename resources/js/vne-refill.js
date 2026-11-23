import axios from 'axios';

const firstError = (e) => e.response?.data?.message ?? e.message;
const euro = (cents) => (cents / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Configurazione > Cassetto contanti: caricamento del fondo cassa. La macchina va in ricarica,
 * l'operatore inserisce il denaro nelle fessure e la macchina lo conta; a fine caricamento il
 * contenuto diventa il fondo cassa (fine ripristino, tipo 93).
 */
export default (urls) => ({
    open: false,
    pettyCash: '',
    state: 'idle', // idle | starting | loading | ending | confirm | done
    amount: 0,
    message: null,
    error: null,
    timer: null,

    euro,

    // Durante il caricamento il popup resta aperto: la cassa è in ricarica finché non si preme Fine
    busy() {
        return ['starting', 'loading', 'ending'].includes(this.state);
    },

    close() {
        if (this.busy()) return;
        this.open = false;
        if (this.state === 'done') {
            this.state = 'idle';
            this.message = null;
            this.pettyCash = '';
        }
    },

    async start() {
        this.error = null;
        this.message = null;
        this.amount = 0;
        this.state = 'starting';
        try {
            await axios.post(urls.start, { petty_cash: this.pettyCash ? Number(this.pettyCash) : null });
            this.state = 'loading';
            this.poll();
        } catch (e) {
            this.state = 'idle';
            this.error = firstError(e);
        }
    },

    async poll() {
        clearTimeout(this.timer);
        if (this.state !== 'loading') return;
        try {
            const { data } = await axios.post(urls.status);
            this.amount = data.amount;
        } catch {
            // un giro perso non ferma il caricamento
        }
        this.timer = setTimeout(() => this.poll(), 1500);
    },

    async finish() {
        clearTimeout(this.timer);
        this.error = null;
        this.state = 'ending';
        try {
            const { data } = await axios.post(urls.end);
            this.amount = data.amount;
        } catch (e) {
            this.state = 'loading';
            this.error = firstError(e);
            this.poll();
            return;
        }
        await this.confirm();
    },

    // Dopo la fine ricarica la macchina mescola le monete (~30 s): si riprova finché accetta
    async confirm() {
        this.state = 'ending';
        this.error = null;
        this.message = 'La cassa sta sistemando le monete, attendi…';
        let last = null;
        for (let i = 0; i < 10; i++) {
            try {
                const { data } = await axios.post(urls.confirm);
                this.state = 'done';
                this.message = `Caricati ${euro(this.amount)}. Fondo cassa registrato: ora in macchina per il resto ci sono ${euro(data.total)}, compreso quello che c'era già.`;
                return;
            } catch (e) {
                last = e;
                await wait(5000);
            }
        }
        this.state = 'confirm';
        this.message = null;
        this.error = `La cassa non ha confermato il fondo (${firstError(last)}). Riprova fra un minuto.`;
    },
});
