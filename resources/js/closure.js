import axios from 'axios';
import * as axon from './fiscal/axon.js';
import { askConfirm } from './confirm';

const firstError = (e) => e.response?.data?.message ?? e.message ?? 'Errore imprevisto.';

/** Report Z sulla stampante Epson (FPMate): ritorna il numero di chiusura. */
async function printEpsonZ({ url, xml }) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'text/xml;charset=utf-8' }, body: xml });
    const text = await res.text();
    const doc = new DOMParser().parseFromString(text, 'text/xml');
    const response = doc.getElementsByTagName('response')[0];
    if (!response || response.getAttribute('success') !== 'true') {
        throw new Error('la stampante ha rifiutato la chiusura (' + (response?.getAttribute('status') ?? text.slice(0, 120)) + ')');
    }
    return doc.getElementsByTagName('zRepNumber')[0]?.textContent ?? '?';
}

/**
 * Chiusura fiscale RT dal browser della postazione (stampante sulla rete del negozio), registrata
 * su Orio. Usata dal pulsante "Chiudi giornata" e dalla chiusura automatica delle 23.
 */
export async function fiscalClosure({ fiscalUrl, confirmUrl, automatic = false }) {
    const { data } = await axios.post(fiscalUrl, { automatic });
    if (data.driver === 'axon' || data.driver === 'epson') {
        const zNumber = data.driver === 'axon' ? await axon.printZ(data) : await printEpsonZ(data);
        return (await axios.post(confirmUrl, { z_number: zNumber, automatic })).data.closure;
    }
    return data.closure;
}

/** Chiusura della cassa automatica VNE per la chiusura indicata. */
export async function vneClosure(vneUrl, closureId) {
    await axios.post(vneUrl.replace('__ID__', closureId));
}

export { firstError };

/**
 * Pulsante "Chiudi giornata": prima la chiusura fiscale RT (obbligatoria), poi la chiusura
 * della cassa automatica VNE. Se la VNE non risponde la chiusura fiscale resta valida e si
 * puo' ripetere solo il passo VNE.
 */
export default ({ fiscalUrl, confirmUrl, vneUrl, pendingVneId = null, summary = [], fiscalClosedToday = false }) => ({
    step: null, // null | 'fiscal' | 'vne' | 'done'
    error: null,
    closureId: pendingVneId,

    async closeDay() {
        const ok = await askConfirm({
            title: 'Chiudere la giornata?',
            message: 'Viene fatta la chiusura fiscale della stampante RT, con l\'invio dei corrispettivi, e poi la chiusura della cassa automatica.',
            items: summary,
            warning: fiscalClosedToday ? 'La chiusura fiscale di oggi è già stata fatta: ne verrà stampata un\'altra.' : '',
            confirmLabel: 'Chiudi giornata',
        });
        if (!ok) return;

        this.error = null;
        this.step = 'fiscal';
        try {
            const closure = await fiscalClosure({ fiscalUrl, confirmUrl });
            this.closureId = closure.id;
        } catch (e) {
            this.error = 'Chiusura fiscale non riuscita: ' + firstError(e);
            this.step = null;
            return;
        }
        await this.closeVne();
    },

    async closeVne() {
        this.error = null;
        this.step = 'vne';
        try {
            await vneClosure(vneUrl, this.closureId);
            this.step = 'done';
            window.location.reload();
        } catch (e) {
            this.error = 'Chiusura fiscale fatta. La cassa automatica non ha risposto: ' + firstError(e);
            this.step = null;
        }
    },

});
