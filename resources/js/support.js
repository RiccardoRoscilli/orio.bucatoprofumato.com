import axios from 'axios';

// html2canvas caricato solo quando serve. Versione "pro": la 1.4.1 di ERP ed emmycasa non legge
// i colori oklch di Tailwind 4 e lo screenshot fallirebbe sempre.
const HTML2CANVAS = 'https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.8/dist/html2canvas-pro.min.js';
let loading = null;
const loadHtml2canvas = () => {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = HTML2CANVAS;
        script.onload = () => resolve(window.html2canvas);
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return loading;
};

/** Finestra "Segnala un problema": titolo, descrizione, screenshot della pagina e allegato. */
export default () => ({
    open: false,
    title: '',
    description: '',
    screenshot: null,
    screenshotUrl: null,
    capturing: false,
    includeScreenshot: true,
    sending: false,
    error: null,
    sent: null,
    pageUrl: '',

    async show() {
        Object.assign(this, { title: '', description: '', screenshot: null, screenshotUrl: null, includeScreenshot: true, error: null, sent: null, pageUrl: location.href, capturing: true });
        if (this.$refs.extra) this.$refs.extra.value = '';
        // Screenshot della pagina com'era, prima di aprire la finestra
        try {
            const html2canvas = await loadHtml2canvas();
            // Solo la parte visibile: quello che l'operatore sta guardando, non tutta la pagina
            const canvas = await html2canvas(document.body, {
                scale: Math.min(1, 1400 / window.innerWidth), useCORS: true, logging: false,
                x: window.scrollX, y: window.scrollY, width: window.innerWidth, height: window.innerHeight,
            });
            this.screenshot = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
            this.screenshotUrl = this.screenshot ? URL.createObjectURL(this.screenshot) : null;
        } catch {
            this.screenshot = null;
        }
        this.capturing = false;
        this.open = true;
        this.$nextTick(() => this.$refs.title?.focus());
    },

    async send() {
        if (!this.title.trim() || !this.description.trim()) {
            this.error = 'Scrivi un titolo e una descrizione.';
            return;
        }
        this.sending = true;
        this.error = null;
        const form = new FormData();
        form.append('title', this.title.trim());
        form.append('description', this.description.trim());
        form.append('page_url', this.pageUrl);
        if (this.screenshot && this.includeScreenshot) form.append('image', this.screenshot, 'screenshot.png');
        if (this.$refs.extra?.files[0]) form.append('extra_image', this.$refs.extra.files[0]);
        try {
            const { data } = await axios.post('/assistenza', form);
            this.sent = data.message;
            setTimeout(() => { this.open = false; }, 1800);
        } catch (e) {
            const data = e.response?.data;
            this.error = data?.errors ? Object.values(data.errors)[0][0] : (data?.message ?? 'Invio non riuscito.');
        }
        this.sending = false;
    },
});
