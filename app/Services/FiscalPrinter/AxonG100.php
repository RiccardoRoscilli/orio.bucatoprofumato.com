<?php

namespace App\Services\FiscalPrinter;

use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Comandi per la stampante fiscale Axon Micrelec DADO RT (firmware G100) della WyCash 600.
 *
 * La stampante si pilota dal browser della postazione con le "API WEB" del firmware G100:
 * ogni comando di protocollo SF20 viaggia in GET su http://IP/_io?cmd=4&js=1&pkt=<comando>
 * e lo stato (con l'ultimo numero di chiusura e di documento) su http://IP/_io?cmd=0.
 * Qui si preparano solo le righe di comando; l'invio e' in resources/js/fiscal/axon.js.
 *
 * Campi separati da "/", importi col punto decimale. Reparti e numeri di pagamento devono
 * corrispondere a quanto programmato sulla stampante: si verificano da Impostazioni > Stampante.
 */
class AxonG100
{
    /** Righe di vendita + pagamento: il pagamento chiude il documento commerciale. */
    public static function receipt(Sale $sale): array
    {
        $commands = [];

        foreach ($sale->items as $item) {
            // 3/S/descrizione/descr.aggiuntiva/qta/prezzo unitario/reparto/aliquota IVA/
            $commands[] = self::packet([
                '3', 'S',
                self::text($item->description, 30),
                '',
                number_format((float) $item->qty, 3, '.', ''),
                self::money($item->unit_price_cents),
                self::department($item->vat_rate),
                number_format($item->vat_rate, 2, '.', ''),
            ]);
        }

        // 5/numero pagamento/importo/ - la tipologia (contanti o elettronico) e' quella
        // programmata sul numero di pagamento. Importo esatto: il resto lo da' la VNE.
        $electronic = in_array($sale->payment_method, ['carta', 'pos'], true);
        $commands[] = self::packet([
            '5',
            (string) config($electronic ? 'orio.fiscal_printer.payment_card' : 'orio.fiscal_printer.payment_cash'),
            self::money($sale->paid_cents ?: $sale->total_cents),
            '', '', '',
        ]);

        return $commands;
    }

    /**
     * Annullo di uno scontrino emesso da questa stampante (comando [+] tipo 1): data di emissione,
     * numero di chiusura e numero del documento originale. La stampante emette da sola l'intero
     * documento commerciale di annullo prendendo le righe dal giornale elettronico.
     */
    public static function voidReceipt(Sale $sale): array
    {
        if (! preg_match('/^(\d{1,4})-(\d{1,4})$/', (string) $sale->receipt_number, $m) || ! $sale->closed_at) {
            throw new \InvalidArgumentException("Numero scontrino «{$sale->receipt_number}» non riconosciuto: annulla lo scontrino dal menu della stampante.");
        }

        // +/1/GGMMAAAA/azzeramento/numero/lotteria/matricola/altri/
        return [self::packet(['+', '1', $sale->closed_at->format('dmY'), (string) (int) $m[1], (string) (int) $m[2], '', '', ''])];
    }

    /** Chiusura giornaliera (report Z): chiude la giornata e avvia l'invio dei corrispettivi. */
    public static function zReport(): array
    {
        return [self::packet(['x', '7', '', '', '', '', ''])];
    }

    /** Annulla il documento rimasto aperto (es. una riga rifiutata a meta' scontrino). */
    public static function abortOpenDocument(): string
    {
        return self::packet(['+', '0']);
    }

    public static function department(int $vatRate): string
    {
        return (string) (config('orio.fiscal_printer.departments')[$vatRate] ?? 1);
    }

    private static function packet(array $fields): string
    {
        return implode('/', $fields) . '/';
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Il "/" e' il separatore dei campi; accenti tolti perche' la stampante non e' UTF-8. */
    private static function text(string $value, int $max): string
    {
        $value = str_replace(['/', "\r", "\n"], ['-', ' ', ' '], Str::ascii($value));

        return mb_substr(trim($value), 0, $max);
    }
}
