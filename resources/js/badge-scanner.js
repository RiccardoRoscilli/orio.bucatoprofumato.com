import axios from 'axios';
import { askConfirm } from './confirm';

/**
 * Bip del badge da qualsiasi pagina di Orio, qualunque sia il campo attivo. Il lettore scrive
 * come una tastiera ma molto piu' veloce di una persona: una sequenza ORIO + 8 lettere/cifre
 * battuta di fila e chiusa da Invio e' un badge. I caratteri non finiscono nei campi (il primo,
 * se e' finito in un campo, viene tolto) e la presenza viene registrata; poi si torna alla Cassa.
 *
 * Sulla Cassa la finestra "Chi sta facendo cassa?" intercetta l'evento orio:badge e usa il bip
 * per scegliere la cassiera invece che per entrata/uscita.
 */

const BADGE = /^ORIO[A-Z0-9]{8}$/i;
const PREFIX = /^O(R(I(O[A-Z0-9]{0,8})?)?)?$/i;
const FAST_MS = 60; // tra un tasto e l'altro: il lettore sta sotto, una persona sopra

let buffer = '';
let origin = null; // campo attivo e suo valore prima del primo carattere
let held = []; // caratteri trattenuti (non scritti nel campo)
let last = 0;
let busy = false;

const editable = (el) => el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') && !el.readOnly;

function snapshot() {
    const el = document.activeElement;
    return editable(el) ? { el, value: el.value, start: el.selectionStart } : null;
}

function reset() {
    buffer = '';
    origin = null;
    held = [];
}

// Non era un badge: i caratteri trattenuti tornano nel campo
function release() {
    if (held.length && origin && document.activeElement === origin.el) {
        document.execCommand('insertText', false, held.join(''));
    }
    reset();
}

// Il primo carattere del badge e' finito nel campo: si rimette il valore di prima
function restore(saved) {
    if (!saved || saved.el.value === saved.value) return;
    saved.el.value = saved.value;
    try {
        saved.el.setSelectionRange(saved.start, saved.start);
    } catch {
        // campi che non hanno il cursore (es. number)
    }
    saved.el.dispatchEvent(new Event('input', { bubbles: true }));
}

function onKeydown(e) {
    const now = performance.now();
    const fast = now - last < FAST_MS;
    last = now;

    if (e.key === 'Enter') {
        if (BADGE.test(buffer) && fast) {
            e.preventDefault();
            e.stopImmediatePropagation();
            const code = buffer.toUpperCase();
            const saved = origin;
            reset();
            restore(saved);
            handleBadge(code);
        } else {
            release();
        }
        return;
    }

    if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) return; // Shift e simili: solo tempo

    if (buffer && fast && PREFIX.test(buffer + e.key)) {
        buffer += e.key;
        held.push(e.key);
        e.preventDefault();
        e.stopImmediatePropagation();
        return;
    }

    release();
    if (/^o$/i.test(e.key)) {
        origin = snapshot();
        buffer = e.key; // il primo passa: non si sa ancora se e' il lettore
    }
}

/* ---------- Finestra dei messaggi ---------- */

let panel = null;
let closeTimer = null;

function show(html, { autoClose = 0 } = {}) {
    clearTimeout(closeTimer);
    if (!panel) {
        panel = document.createElement('div');
        panel.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-stone-900/60 p-4';
        document.body.appendChild(panel);
    }
    panel.innerHTML = `<div class="card w-full max-w-md p-8 text-center shadow-2xl">${html}</div>`;
    panel.hidden = false;
    if (autoClose) closeTimer = setTimeout(hide, autoClose);
    return panel;
}

function hide() {
    clearTimeout(closeTimer);
    if (panel) panel.hidden = true;
}

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// Dopo entrata/uscita da un'altra pagina si torna alla Cassa
function backToCassa(delay) {
    if (location.pathname === '/') return;
    setTimeout(() => { location.href = '/'; }, delay);
}

function done(data) {
    window.dispatchEvent(new CustomEvent('orio:present', { detail: data.present }));
    const entry = data.action === 'started';
    show(`
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-brand-50 text-4xl text-brand-600">${entry ? '✓' : '👋'}</div>
        <div class="mt-4 text-2xl font-bold">${esc(data.message)}</div>`, { autoClose: 3000 });
    backToCassa(3000);
}

function error(message) {
    show(`
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-50 text-4xl text-red-600">!</div>
        <div class="mt-4 text-lg font-semibold">${esc(message)}</div>
        <button type="button" class="btn-light mt-6 w-full" data-close>Chiudi</button>`).querySelector('[data-close]').onclick = hide;
    setTimeout(hide, 8000);
}

// Tablet non abilitato: si scrive il codice di 6 cifre dato da un admin, poi si riprova il badge
function askDeviceCode(code, message) {
    const el = show(`
        <div class="text-lg font-semibold">${esc(message)}</div>
        <input inputmode="numeric" maxlength="7" autocomplete="off" placeholder="000 000"
               class="input mt-5 text-center font-mono text-3xl tracking-[0.3em]" data-code>
        <p class="mt-2 hidden text-sm text-red-700" data-error></p>
        <div class="mt-5 grid grid-cols-2 gap-2">
            <button type="button" class="btn-light" data-close>Annulla</button>
            <button type="button" class="btn-primary" data-ok>Abilita</button>
        </div>`);
    const input = el.querySelector('[data-code]');
    const err = el.querySelector('[data-error]');
    const submit = async () => {
        try {
            await axios.post('/presenze/abilita', { code: input.value });
            hide();
            handleBadge(code);
        } catch (e) {
            err.textContent = e.response?.data?.message ?? 'Codice non valido.';
            err.classList.remove('hidden');
            input.select();
        }
    };
    el.querySelector('[data-close]').onclick = hide;
    el.querySelector('[data-ok]').onclick = submit;
    input.onkeydown = (e) => { if (e.key === 'Enter') submit(); };
    setTimeout(() => input.focus(), 50);
}

/** Registra entrata o uscita per il badge (anche dalla cassa, se il lettore e' lento). */
export async function handleBadge(code) {
    // La cassa puo' usare il bip per altro (scelta della cassiera)
    const event = new CustomEvent('orio:badge', { detail: { code }, cancelable: true });
    if (!window.dispatchEvent(event)) return;
    if (busy) return;
    busy = true;
    try {
        let { data } = await axios.post('/presenze/badge', { code });
        if (data.action === 'ask_end') {
            hide();
            const ok = await askConfirm({
                title: `${data.user.name}, chiudi il turno?`,
                items: [{ label: 'Entrata', value: data.user.since }],
                confirmLabel: 'Sì, esco',
            });
            if (!ok) return;
            ({ data } = await axios.post('/presenze/badge', { code, end: true }));
        }
        done(data);
    } catch (e) {
        const data = e.response?.data;
        if (data?.device_required) {
            askDeviceCode(code, data.message);
        } else {
            error(data?.message ?? e.message);
        }
    } finally {
        busy = false;
    }
}

export function registerBadgeScanner() {
    // Solo con un utente collegato (il layout lo segnala): niente sulla pagina di login
    if (!document.querySelector('meta[name="orio-auth"]')) return;
    window.addEventListener('keydown', onKeydown, true);
}
