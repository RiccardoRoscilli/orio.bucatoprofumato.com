import axios from 'axios';

let seq = 0;
const firstError = (e) => e.response?.data?.message ?? e.message;

// Configurazione > Terminali carta: righe modificabili e prova della chiave di ognuno
export default (terminals = [], driver = 'manual', defaultKey = null) => ({
    driver,
    defaultKey,
    // Dopo un errore di salvataggio le righe tornano dal form: la chiave scritta non viene riproposta
    terminals: terminals.map((t) => ({ ...t, api_key: '', active: !!t.active && t.active !== '0', uid: ++seq, result: null, testing: false })),

    add() {
        this.terminals.push({ name: '', id: '', api_key: '', active: true, original: null, key_hint: null, uid: ++seq, result: null, testing: false });
    },

    async remove(i) {
        const t = this.terminals[i];
        if (t.original && !(await window.askConfirm({
            title: 'Rimuovere il terminale?',
            message: 'Sparisce dalla cassa dopo il salvataggio. Le vendite già incassate restano.',
            items: [{ label: 'Terminale', value: `${t.name} · TID ${t.id}` }],
            confirmLabel: 'Rimuovi',
            danger: true,
        }))) return;
        this.terminals.splice(i, 1);
    },

    async test(t) {
        t.testing = true;
        t.result = null;
        try {
            const { data } = await axios.post('/configurazione/prova-pos', { terminal: t.original });
            t.result = data;
        } catch (e) {
            t.result = { ok: false, message: firstError(e) };
        }
        t.testing = false;
    },
});
