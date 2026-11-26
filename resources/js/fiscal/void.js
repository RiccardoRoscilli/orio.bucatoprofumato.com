import axios from 'axios';
import * as axon from './axon.js';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Annullo di una vendita incassata: il server dice se serve il documento di annullo sulla
 * stampante; in quel caso il browser della postazione lo stampa e conferma il numero.
 * Se c'erano contanti della cassa automatica, segue la loro restituzione (onProgress riceve
 * i messaggi "sta restituendo…").
 * Restituisce { state: 'done' | 'refund_failed' | 'unreachable', message }.
 */
export async function voidSale(saleId, onProgress = () => {}) {
    const { data } = await axios.post(`/vendite/${saleId}/storno`);
    if (data.state !== 'printer') return data.cash_refund ? refundCash(saleId, onProgress) : data;

    const number = await axon.printReceipt(data);
    const confirmed = await axios.post(`/vendite/${saleId}/storno/conferma`, { void_number: number });

    return confirmed.data.cash_refund ? refundCash(saleId, onProgress) : confirmed.data;
}

/** Restituzione dei contanti dalla cassa automatica: un passo al secondo finché non finisce. */
export async function refundCash(saleId, onProgress = () => {}) {
    for (let i = 0; i < 120; i++) {
        const { data } = await axios.post(`/vendite/${saleId}/storno/contanti`);
        if (data.state !== 'refunding') return data;
        onProgress(data.message);
        await wait(1000);
    }

    return { state: 'unreachable', message: 'La cassa automatica non ha finito di restituire i contanti: controlla sulla macchina e riprova dalla scheda della vendita.' };
}
