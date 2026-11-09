import axios from 'axios';
import * as axon from './axon.js';

/**
 * Emissione dello scontrino di una vendita pagata, uguale dalla cassa e dalla pagina vendita:
 * il server prepara i comandi, il browser della postazione li manda alla stampante fiscale in
 * rete locale e poi conferma a Orio il numero del documento. Restituisce la vendita aggiornata
 * e, con la stampante simulata, l'URL dell'anteprima.
 */
export async function emitReceipt(saleId) {
    const { data } = await axios.post(`/vendite/${saleId}/scontrino`);

    if (data.driver === 'fake') return { sale: data.sale, previewUrl: data.preview_url };

    const number = data.driver === 'axon' ? await axon.printReceipt(data) : await printOnEpson(data);
    const confirmed = await axios.post(`/vendite/${saleId}/scontrino/conferma`, { receipt_number: number });

    return { sale: confirmed.data.sale };
}

// Epson FPMate: deve essere raggiungibile in HTTPS con certificato attendibile (docs/SPECIFICHE.md §4 e §7).
async function printOnEpson({ url, xml }) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'text/xml;charset=utf-8' }, body: xml });
    const text = await res.text();
    const doc = new DOMParser().parseFromString(text, 'text/xml');
    const response = doc.getElementsByTagName('response')[0];
    if (!response || response.getAttribute('success') !== 'true') {
        throw new Error('La stampante ha rifiutato lo scontrino: ' + (response?.getAttribute('status') ?? text.slice(0, 120)));
    }
    return doc.getElementsByTagName('fiscalReceiptNumber')[0]?.textContent ?? '?';
}
