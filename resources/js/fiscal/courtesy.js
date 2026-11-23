import axios from 'axios';
import * as axon from './axon.js';

/**
 * Scontrino di cortesia (documento non fiscale) di uno scontrino emesso: il server prepara i
 * comandi, il browser della postazione li manda alla stampante. Con la stampante simulata
 * restituisce l'URL dell'anteprima.
 */
export async function printCourtesy(saleId) {
    const { data } = await axios.post(`/vendite/${saleId}/cortesia`);

    if (data.driver === 'fake') return { previewUrl: data.preview_url };

    if (data.driver === 'axon') {
        try {
            for (const pkt of data.commands) await axon.command(data.base, pkt);
        } catch (e) {
            // chiude comunque il documento gestionale, per non lasciare la stampante bloccata
            await axon.command(data.base, 'm/').catch(() => {});
            throw e;
        }
        return {};
    }

    const res = await fetch(data.url, { method: 'POST', headers: { 'Content-Type': 'text/xml;charset=utf-8' }, body: data.xml });
    const text = await res.text();
    const response = new DOMParser().parseFromString(text, 'text/xml').getElementsByTagName('response')[0];
    if (!response || response.getAttribute('success') !== 'true') {
        throw new Error('La stampante ha rifiutato lo scontrino di cortesia: ' + (response?.getAttribute('status') ?? text.slice(0, 120)));
    }
    return {};
}
