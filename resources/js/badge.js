/**
 * Solo il QR del badge in SVG, per il grafico: bordo bianco di 4 moduli compreso (serve al
 * lettore). La libreria si carica solo al clic.
 */
export async function downloadBadgeSvg(code, filename) {
    const { default: QRCode } = await import('qrcode');
    const svg = await QRCode.toString(code, { type: 'svg', margin: 4, errorCorrectionLevel: 'M' });
    const a = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' })),
        download: filename,
    });
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
