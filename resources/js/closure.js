import axios from 'axios';
import * as axon from './fiscal/axon.js';

const firstError = (e) => e.response?.data?.message ?? e.message ?? 'Errore imprevisto.';

/**
 * Pulsante "Chiudi giornata": prima la chiusura fiscale RT (obbligatoria), poi la chiusura
 * della cassa automatica VNE. Se la VNE non risponde la chiusura fiscale resta valida e si
 * puo' ripetere solo il passo VNE.
 */
export default ({ fiscalUrl, confirmUrl, vneUrl, pendingVneId = null }) => ({
    step: null, // null | 'fiscal' | 'vne' | 'done'
    error: null,
    closureId: pendingVneId,

    async closeDay() {
        if (!confirm('Eseguire la chiusura giornata?\n\n1. Chiusura fiscale della stampante RT (invio corrispettivi)\n2. Chiusura della cassa automatica VNE')) return;

        this.error = null;
        this.step = 'fiscal';
        try {
            const { data } = await axios.post(fiscalUrl);
            let closure = data.closure;
            if (data.driver === 'axon' || data.driver === 'epson') {
                const zNumber = data.driver === 'axon' ? await axon.printZ(data) : await this.printZ(data);
                closure = (await axios.post(confirmUrl, { z_number: zNumber })).data.closure;
            }
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
            await axios.post(vneUrl.replace('__ID__', this.closureId));
            this.step = 'done';
            window.location.reload();
        } catch (e) {
            this.error = 'Chiusura fiscale fatta. La cassa automatica non ha risposto: ' + firstError(e);
            this.step = null;
        }
    },

    async printZ({ url, xml }) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'text/xml;charset=utf-8' }, body: xml });
        const text = await res.text();
        const doc = new DOMParser().parseFromString(text, 'text/xml');
        const response = doc.getElementsByTagName('response')[0];
        if (!response || response.getAttribute('success') !== 'true') {
            throw new Error('la stampante ha rifiutato la chiusura (' + (response?.getAttribute('status') ?? text.slice(0, 120)) + ')');
        }
        return doc.getElementsByTagName('zRepNumber')[0]?.textContent ?? '?';
    },
});
