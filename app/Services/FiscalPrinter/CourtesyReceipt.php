<?php

namespace App\Services\FiscalPrinter;

use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Scontrino di cortesia: documento NON fiscale (documento gestionale) con i prodotti e le
 * quantità di uno scontrino già emesso, senza prezzi, da dare al cliente per un regalo o un cambio.
 * Le righe sono le stesse per la stampante e per l'anteprima.
 */
class CourtesyReceipt
{
    /** Larghezza della riga sulla stampante (protocollo G100: 0-48 caratteri). */
    public const WIDTH = 48;

    /** @return list<array{text: string, style: string}> style: title | bold | normal */
    public static function lines(Sale $sale): array
    {
        $lines = [
            ['text' => 'SCONTRINO DI CORTESIA', 'style' => 'title'],
            ['text' => 'Non valido ai fini fiscali', 'style' => 'normal'],
            ['text' => '', 'style' => 'normal'],
            ['text' => 'Documento commerciale n. ' . $sale->receipt_number, 'style' => 'normal'],
            ['text' => 'del ' . $sale->closed_at?->format('d.m.Y H:i'), 'style' => 'normal'],
            ['text' => str_repeat('-', self::WIDTH), 'style' => 'normal'],
        ];

        foreach ($sale->items as $item) {
            $qty = rtrim(rtrim(number_format((float) $item->qty, 3, ',', ''), '0'), ',');
            foreach (self::wrap("{$qty} x " . mb_strtoupper($item->description)) as $text) {
                $lines[] = ['text' => $text, 'style' => 'normal'];
            }
        }

        $lines[] = ['text' => str_repeat('-', self::WIDTH), 'style' => 'normal'];
        $lines[] = ['text' => 'Pezzi: ' . rtrim(rtrim(number_format((float) $sale->items->sum('qty'), 3, ',', ''), '0'), ','), 'style' => 'bold'];
        $lines[] = ['text' => 'Conservalo per un eventuale cambio.', 'style' => 'normal'];

        return $lines;
    }

    /**
     * Axon G100: intestazione del negozio (7/1/20), righe di testo (7/1/tipo/testo) e chiusura
     * del documento gestionale con taglio carta (m).
     */
    public static function axon(Sale $sale): array
    {
        $types = ['title' => '2', 'bold' => '5', 'normal' => '1'];
        $commands = ['7/1/20//'];
        foreach (self::lines($sale) as $line) {
            $commands[] = '7/1/' . $types[$line['style']] . '/' . self::ascii($line['text']) . '/';
        }
        $commands[] = 'm/';

        return $commands;
    }

    /** Epson FPMate: documento non fiscale. */
    public static function epson(Sale $sale): string
    {
        $rows = ['<beginNonFiscal operator="1" />'];
        foreach (self::lines($sale) as $line) {
            $rows[] = sprintf('<printNormal operator="1" font="1" data="%s" />', htmlspecialchars($line['text'], ENT_XML1 | ENT_QUOTES));
        }
        $rows[] = '<endNonFiscal operator="1" />';

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">' . "\n"
            . "<s:Body>\n<printerNonFiscal>\n  " . implode("\n  ", $rows) . "\n</printerNonFiscal>\n</s:Body>\n</s:Envelope>";
    }

    /** @return list<string> righe di al massimo WIDTH caratteri, a capo sulle parole */
    private static function wrap(string $text): array
    {
        return explode("\n", wordwrap($text, self::WIDTH, "\n", true));
    }

    /** Il "/" separa i campi e la stampante non è UTF-8. */
    private static function ascii(string $text): string
    {
        return mb_substr(str_replace(['/', "\r", "\n"], ['-', ' ', ' '], Str::ascii($text)), 0, self::WIDTH);
    }
}
