import Alpine from 'alpinejs';

/**
 * Finestra di conferma nello stile di Orio, al posto del confirm() del browser.
 *
 *   if (!await askConfirm({ title, message, items, warning, confirmLabel, danger })) return;
 *
 * Nei form basta l'attributo data-confirm="Testo della domanda" (piu' data-confirm-label
 * e data-confirm-danger opzionali): il form viene inviato solo dopo la conferma.
 */
const empty = { open: false, title: '', message: '', items: [], warning: '', confirmLabel: 'Conferma', danger: false };

export function registerConfirm() {
    Alpine.store('confirm', {
        ...empty,
        resolve: null,
        answer(value) {
            this.open = false;
            this.resolve?.(value);
            this.resolve = null;
        },
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed) return;
        event.preventDefault();
        const ok = await askConfirm({
            title: form.dataset.confirm,
            confirmLabel: form.dataset.confirmLabel || 'Conferma',
            danger: 'confirmDanger' in form.dataset,
        });
        if (!ok) return;
        form.dataset.confirmed = '1';
        form.requestSubmit(event.submitter ?? undefined);
    });
}

export function askConfirm(options) {
    const store = Alpine.store('confirm');
    store.resolve?.(false);
    return new Promise((resolve) => {
        Object.assign(store, empty, options, { open: true, resolve });
    });
}
