/** QR del badge: la libreria si carica solo dove serve (stampa, scarica SVG). */
const qr = async (code, margin) => {
    const { default: QRCode } = await import('qrcode');
    return QRCode.toString(code, { type: 'svg', margin, errorCorrectionLevel: 'M' });
};

export default (code) => ({
    async init() {
        this.$el.innerHTML = await qr(code, 0);
    },
});

/** Solo il QR in SVG, per il grafico: bordo bianco di 4 moduli compreso (serve al lettore). */
export async function downloadBadgeSvg(code, filename) {
    const blob = new Blob([await qr(code, 4)], { type: 'image/svg+xml' });
    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: filename });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
