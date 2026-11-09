/**
 * Stampante fiscale Axon Micrelec DADO RT (firmware G100, WyCash 600) via "API WEB":
 *   stato:    GET http://IP/_io?cmd=0                 -> JSON con ej.lastdoc { zno, docno }
 *   comando:  GET http://IP/_io?cmd=4&js=1&pkt=<SF20>  -> risposta del protocollo SF20
 *
 * La risposta SF20 inizia con il codice esito ("00" = ok, altrimenti codice errore esadecimale).
 * Per lo scontrino l'esito vero si controlla comunque sul giornale elettronico: il numero
 * dell'ultimo documento deve essere cambiato, e diventa il numero dello scontrino.
 */

const ERRORS = {
    0x09: 'codice IVA errato',
    0x10: 'giornata aperta da troppo, serve prima la chiusura giornaliera',
    0x13: 'vendite non consentite',
    0x14: "c'è un documento aperto sulla stampante",
    0x15: 'scontrino in fase di pagamento',
    0x17: 'aliquota IVA non corrispondente al reparto',
    0x18: 'prezzo non valido',
    0x1a: 'stampante occupata',
    0x2c: 'carta finita',
    0x2d: 'guasto taglierina',
    0x2e: 'stampante scollegata',
    0x2f: 'stampante surriscaldata',
    0x31: 'errore grave della memoria fiscale',
};

export class AxonError extends Error {}

async function call(url) {
    let res;
    try {
        // targetAddressSpace: con Orio in https, Chrome chiede il permesso "dispositivi della
        // rete locale" e poi consente la chiamata in http all'IP della stampante.
        res = await fetch(url, { cache: 'no-store', targetAddressSpace: 'local' });
    } catch {
        throw new AxonError(
            'il tablet non riesce a raggiungere la stampante fiscale. Controlla che sia accesa e in rete; ' +
            'se Orio è in https, sul tablet va abilitato in Chrome l\'indirizzo della stampante (vedi Impostazioni > Stampante).'
        );
    }
    if (!res.ok) throw new AxonError(`la stampante ha risposto HTTP ${res.status}`);
    return res.text();
}

export async function status(base) {
    const text = await call(`${base}/_io?cmd=0`);
    try {
        return JSON.parse(text);
    } catch {
        throw new AxonError('risposta di stato non valida: ' + text.slice(0, 120));
    }
}

/** Estrae la risposta SF20 anche se arriva dentro un JSON (js=1). */
export function rawReply(text) {
    try {
        const json = JSON.parse(text);
        const found = Object.values(json).find((v) => typeof v === 'string' && /^\s*[0-9A-Fa-f]{2}(\/|$)/.test(v));
        return found ?? text;
    } catch {
        return text;
    }
}

export function replyError(text) {
    const code = rawReply(text).trim().split('/')[0];
    if (!/^[0-9A-Fa-f]{2}$/.test(code) || code === '00') return null;
    const n = parseInt(code, 16);
    return `errore ${n} (0x${code.toUpperCase()})` + (ERRORS[n] ? `: ${ERRORS[n]}` : '');
}

export async function command(base, pkt) {
    for (let attempt = 0; ; attempt++) {
        const text = await call(`${base}/_io?cmd=4&js=1&pkt=${encodeURIComponent(pkt)}`);
        const error = replyError(text);
        if (!error) return text;
        // stampante occupata: riprova qualche volta
        if (error.includes('0x1A') && attempt < 5) {
            await new Promise((r) => setTimeout(r, 800));
            continue;
        }
        throw new AxonError(`comando "${pkt}" rifiutato, ${error}`);
    }
}

const lastDoc = (s) => s?.ej?.lastdoc ?? null;
const pad = (n) => String(n ?? 0).padStart(4, '0');

/** Stampa lo scontrino e restituisce il numero documento "ZZZZ-NNNN". */
export async function printReceipt({ base, commands, abort }) {
    const before = lastDoc(await status(base));

    try {
        for (const pkt of commands) await command(base, pkt);
    } catch (e) {
        // non lasciare un documento a meta' aperto sulla stampante
        if (abort) await command(base, abort).catch(() => {});
        throw e;
    }

    const after = lastDoc(await status(base));
    if (before && after && after.zno === before.zno && after.docno === before.docno) {
        throw new AxonError('la stampante non ha registrato lo scontrino (numero documento invariato).');
    }

    return after ? `${pad(after.zno)}-${pad(after.docno)}` : '?';
}

/** Chiusura giornaliera Z: restituisce il numero della chiusura. */
export async function printZ({ base, commands }) {
    const before = lastDoc(await status(base));
    for (const pkt of commands) await command(base, pkt);
    return before ? pad(before.zno) : '?';
}
