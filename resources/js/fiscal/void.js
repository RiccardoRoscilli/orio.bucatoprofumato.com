import axios from 'axios';
import * as axon from './axon.js';

/**
 * Annullo di una vendita incassata: il server dice se serve il documento di annullo sulla
 * stampante; in quel caso il browser della postazione lo stampa e conferma il numero.
 * Restituisce { state: 'done' | 'refund_failed', message }.
 */
export async function voidSale(saleId) {
    const { data } = await axios.post(`/vendite/${saleId}/storno`);
    if (data.state !== 'printer') return data;

    const number = await axon.printReceipt(data);
    const confirmed = await axios.post(`/vendite/${saleId}/storno/conferma`, { void_number: number });

    return confirmed.data;
}
