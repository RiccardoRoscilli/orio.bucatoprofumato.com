/** QR del badge: la libreria si carica solo nella pagina di stampa. */
export default (code) => ({
    async init() {
        const { default: QRCode } = await import('qrcode');
        this.$el.innerHTML = await QRCode.toString(code, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' });
    },
});
