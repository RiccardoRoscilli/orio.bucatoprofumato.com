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

// Indirizzi delle reti locali (192.168.x, 10.x, 172.16-31.x, 169.254.x): solo per questi
// Chrome accetta targetAddressSpace "local"; con un IP fuori da questi intervalli lo rifiuta.
const isPrivateIp = (host) => /^(10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.|127\.)/.test(host);

async function call(url) {
    const host = new URL(url).hostname;
    const options = { cache: 'no-store' };
    // targetAddressSpace: con Orio in https, Chrome chiede il permesso "dispositivi della
    // rete locale" e poi consente la chiamata in http all'IP della stampante.
    if (isPrivateIp(host)) options.targetAddressSpace = 'local';

    let res;
    try {
        res = await fetch(url, options);
    } catch (e) {
        throw new AxonError(await diagnose(url, host, e));
    }
    if (!res.ok) throw new AxonError(`la stampante ha risposto HTTP ${res.status}`);
    return res.text();
}

/** Spiega perché la chiamata non è passata: rete, IP fuori dalla rete locale, permessi di Chrome. */
async function diagnose(url, host, error) {
    const where = 'Gestione > Configurazione > Stampante fiscale';
    if (!isPrivateIp(host)) {
        return `l'indirizzo ${host} non è un indirizzo della rete del negozio (di solito inizia con 192.168.): ` +
            `controlla l'IP in ${where}. Dettaglio: ${error.message}`;
    }
    // Chiamata "opaca": arriva alla stampante anche se il browser non ci lascia leggere la risposta
    let reachable = false;
    try {
        await fetch(url.replace(/cmd=4.*$/, 'cmd=0'), { mode: 'no-cors', cache: 'no-store', targetAddressSpace: 'local' });
        reachable = true;
    } catch {
        // davvero irraggiungibile, o bloccata dai permessi
    }
    if (reachable) {
        return 'la stampante risponde, ma Chrome non lascia leggere la risposta a Orio. Nelle impostazioni del sito di Orio ' +
            '(icona a sinistra dell\'indirizzo > Impostazioni sito) metti su Consenti "Accesso alla rete locale" e "Contenuti non sicuri", ' +
            `poi ricarica la pagina. Dettaglio: ${error.message}`;
    }
    return 'il tablet non riesce a raggiungere la stampante fiscale. Controlla che sia accesa, sulla stessa rete del tablet ' +
        `e che l'IP in ${where} sia giusto; se lo è, in Chrome consenti "Accesso alla rete locale" per il sito di Orio. ` +
        `Dettaglio: ${error.message}`;
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
