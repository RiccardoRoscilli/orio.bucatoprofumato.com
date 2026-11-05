<?php

namespace App\Services\FiscalPrinter;

use App\Models\Sale;

/**
 * XML per FPMate delle stampanti fiscali Epson RT (POST su https://IP/cgi-bin/fpmate.cgi).
 * Da verificare sulla stampante reale con la guida "ePOS Fiscal Print": separatore
 * decimale, reparti associati alle aliquote IVA e codici dei tipi di pagamento.
 */
class EpsonFpMateXml
{
    /** Aliquota IVA => reparto programmato sulla stampante. */
    public const DEPARTMENTS = [22 => 1, 10 => 2, 5 => 3, 4 => 4];

    public static function receipt(Sale $sale): string
    {
        $lines = [];
        $lines[] = '<beginFiscalReceipt operator="1" />';

        foreach ($sale->items as $item) {
            $lines[] = sprintf(
                '<printRecItem operator="1" description="%s" quantity="%s" unitPrice="%s" department="%d" justification="1" />',
                self::text($item->description, 38),
                self::number($item->qty, 3),
                self::money($item->unit_price_cents),
                self::DEPARTMENTS[$item->vat_rate] ?? 1,
            );
        }

        // 0 = contanti, 2 = pagamento elettronico
        $electronic = in_array($sale->payment_method, ['carta', 'pos'], true);
        $lines[] = sprintf(
            '<printRecTotal operator="1" description="%s" payment="%s" paymentType="%d" index="%d" justification="1" />',
            $electronic ? 'PAGAMENTO ELETTRONICO' : 'CONTANTI',
            self::money($sale->paid_cents ?: $sale->total_cents),
            $electronic ? 2 : 0,
            $electronic ? 1 : 0,
        );
        $lines[] = '<endFiscalReceipt operator="1" />';

        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">' . "\n"
            . "<s:Body>\n<printerFiscalReceipt>\n  "
            . implode("\n  ", $lines)
            . "\n</printerFiscalReceipt>\n</s:Body>\n</s:Envelope>";
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    private static function number(float $value, int $decimals): string
    {
        return rtrim(rtrim(number_format($value, $decimals, ',', ''), '0'), ',');
    }

    private static function text(string $value, int $max): string
    {
        return htmlspecialchars(mb_substr($value, 0, $max), ENT_XML1 | ENT_QUOTES);
    }
}
