import axios from 'axios';
import { fiscalClosure, vneClosure, firstError } from './closure';

/**
 * Chiusura automatica della giornata dal tablet della cassa (il dispositivo abilitato alle
 * presenze). Il server non raggiunge stampante e cassa automatica, che sono sulla rete del
 * negozio: lo fa il browser del tablet, che resta acceso su Orio.
 *
 * Ogni minuto, dentro la finestra della sera (es. 23:00-23:55), chiede a Orio se la chiusura
 * di oggi manca; se si', fa chiusura fiscale RT + chiusura VNE come il pulsante "Chiudi
 * giornata". Se non riesce ritenta al giro dopo, fino alla fine della finestra.
 * Tiene anche lo schermo acceso (Screen Wake Lock), altrimenti Chrome congela la pagina.
 */

const CHECK_MS = 60 * 1000;
const RETRY_MS = 5 * 60 * 1000;

let config = null;
let running = false;
let lastAttempt = 0;
let wakeLock = null;

const minutes = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
};

function inWindow() {
    const now = new Date();
    const current = now.getHours() * 60 + now.getMinutes();
    return current >= minutes(config.at) && current <= minutes(config.until);
}

async function keepScreenOn() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible' || wakeLock) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch {
        // batteria scarica o permesso negato: resta l'impostazione del tablet
    }
}

let panel = null;
function show(text, tone = 'brand') {
    if (!panel) {
        panel = document.createElement('div');
        panel.className = 'fixed inset-x-0 top-16 z-[70] mx-auto w-fit max-w-[90vw] rounded-xl px-5 py-3 text-center text-lg font-semibold shadow-xl ring-1';
        document.body.appendChild(panel);
    }
    panel.className = panel.className.replace(/bg-\S+ text-\S+ ring-\S+/g, '')
        + (tone === 'error' ? ' bg-red-50 text-red-800 ring-red-200' : ' bg-brand-50 text-brand-800 ring-brand-200');
    panel.textContent = text;
    panel.hidden = false;
}

async function report(ok, message) {
    try {
        await axios.post(config.resultUrl, { ok, message });
    } catch {
        // l'esito si vede comunque dalle chiusure registrate
    }
}

async function tick() {
    if (running || !inWindow() || Date.now() - lastAttempt < RETRY_MS) return;
    running = true;
    try {
        const { data } = await axios.post(config.startUrl);
        if (!data.due) return;
        lastAttempt = Date.now();

        show('Chiusura automatica della giornata in corso…');
        let closure = data.vne_only ? { id: data.vne_only, fiscal_z_number: null } : null;
        try {
            closure ??= await fiscalClosure({ fiscalUrl: config.fiscalUrl, confirmUrl: config.confirmUrl, automatic: true });
        } catch (e) {
            const message = 'Chiusura fiscale non riuscita: ' + firstError(e);
            show(message + ' Riprovo tra qualche minuto.', 'error');
            await report(false, message);
            return;
        }
        try {
            await vneClosure(config.vneUrl, closure.id);
        } catch (e) {
            const message = 'Chiusura fiscale fatta, cassa automatica non chiusa: ' + firstError(e);
            show(message, 'error');
            await report(false, message);
            return;
        }
        const done = closure.fiscal_z_number ? `Chiusura fatta (Z ${closure.fiscal_z_number}).` : 'Cassa automatica chiusa.';
        await report(true, done);
        show('Giornata chiusa in automatico. ' + done);
        document.querySelectorAll('[data-closure-reminder]').forEach((el) => el.remove());
        setTimeout(() => { if (panel) panel.hidden = true; }, 60 * 1000);
    } catch {
        // Orio non raggiungibile: si riprova al minuto dopo
    } finally {
        running = false;
    }
}

export function registerAutoClosure() {
    const meta = document.querySelector('meta[name="orio-auto-closure"]');
    if (!meta) return;
    config = JSON.parse(meta.content);

    keepScreenOn();
    document.addEventListener('visibilitychange', () => {
        keepScreenOn();
        if (document.visibilityState === 'visible') tick();
    });
    setInterval(tick, CHECK_MS);
    tick();
}
