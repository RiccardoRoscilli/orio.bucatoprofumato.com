import axios from 'axios';

/**
 * Cassa automatica VNE collegata "dal tablet": quando Orio risponde 428 con vne_relay, la
 * richiesta del protocollo parte da qui verso la VNE (rete del negozio) e la stessa chiamata a
 * Orio viene ripetuta con la risposta allegata (BrowserVneClient sul server).
 */

const isPrivateIp = (host) => /^(10\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/.test(host);
const TIMEOUT_MS = 5000; // protocollo VNE: se non risponde entro 5 secondi la richiesta si ripete

// Come mandare il JSON: "json" (Content-Type application/json, la VNE deve accettare il CORS)
// oppure "text" (text/plain, richiesta semplice senza controllo preventivo del browser)
let mode = null;

async function post(url, payload, as) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    const options = {
        method: 'POST',
        body: JSON.stringify(payload),
        headers: { 'Content-Type': as === 'json' ? 'application/json' : 'text/plain' },
        cache: 'no-store',
        signal: controller.signal,
    };
    if (isPrivateIp(new URL(url).hostname)) options.targetAddressSpace = 'local';
    try {
        const res = await fetch(url, options);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch {
            throw new Error(`risposta non valida (HTTP ${res.status}): ${text.slice(0, 120)}`);
        }
    } finally {
        clearTimeout(timer);
    }
}

/** Sceglie il modo con una richiesta innocua (stato, tipo 20) prima di mandare comandi veri. */
async function detectMode(url, opName) {
    const errors = [];
    for (const as of ['json', 'text']) {
        try {
            await post(url, { tipo: 20, opName }, as);
            return as;
        } catch (e) {
            errors.push(e);
        }
    }
    throw errors.at(-1);
}

/** Spiega perché il tablet non ha avuto risposta dalla VNE. */
async function diagnose(url, error) {
    const host = new URL(url).hostname;
    let reachable = false;
    try {
        await fetch(url, { method: 'POST', mode: 'no-cors', body: '{}', cache: 'no-store', targetAddressSpace: 'local' });
        reachable = true;
    } catch {
        // davvero irraggiungibile, certificato non accettato o bloccata dal browser
    }
    if (error.name === 'AbortError') {
        return `la cassa automatica (${host}) non ha risposto entro 5 secondi.`;
    }
    if (reachable) {
        return `la cassa automatica risponde, ma non lascia leggere la risposta a Orio (CORS): va abilitato sulla VNE. Dettaglio: ${error.message}`;
    }
    return `il tablet non raggiunge la cassa automatica: apri https://${host}/selfcashapi/ in una scheda, accetta il certificato ` +
        `("Avanzate" > "Procedi") e riprova. Se non si apre, controlla rete e IP in Gestione > Configurazione. Dettaglio: ${error.message}`;
}

/** Manda il payload alla VNE e restituisce la voce da allegare a Orio. */
export async function relay({ url, payload }) {
    const entry = { request: payload };
    try {
        mode ??= await detectMode(url, payload.opName ?? '');
        try {
            entry.response = await post(url, payload, mode);
        } catch (e) {
            // Nessuna risposta: si ripete una volta, tranne la richiesta di pagamento (non deve partire due volte)
            if (e.name !== 'AbortError' || payload.tipo === 1) throw e;
            entry.response = await post(url, payload, mode);
        }
    } catch (e) {
        mode = null;
        entry.error = await diagnose(url, e);
    }
    return entry;
}

const encode = (list) => btoa(unescape(encodeURIComponent(JSON.stringify(list))));

/** Intercetta le risposte 428 di Orio e ripete la chiamata con la risposta della VNE allegata. */
export function registerVneRelay() {
    axios.interceptors.response.use(null, async (error) => {
        const data = error.response?.data;
        if (error.response?.status !== 428 || !data?.vne_relay) throw error;

        const config = error.config;
        const done = (config.vneRelayed ?? []).slice(0, data.vne_relay.index);
        if (done.length >= 5) throw error;
        config.vneRelayed = [...done, await relay(data.vne_relay)];

        if ((config.method ?? 'get').toLowerCase() === 'get') {
            config.headers['X-Vne-Relay'] = encode(config.vneRelayed);
        } else {
            const body = typeof config.data === 'string' && config.data ? JSON.parse(config.data) : (config.data ?? {});
            config.data = JSON.stringify({ ...body, _vne_relay: config.vneRelayed });
            config.headers['Content-Type'] = 'application/json';
        }
        return axios.request(config);
    });
}
