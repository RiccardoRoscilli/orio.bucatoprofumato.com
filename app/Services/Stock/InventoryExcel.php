<?php

namespace App\Services\Stock;

use App\Models\Product;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Export dell'inventario: giacenza attuale di ogni prodotto in vendita in negozio (più quelli
 * non in vendita che hanno ancora una giacenza), con prezzo in cassa e valore al pubblico.
 * Solo lettura: per caricare le giacenze resta il modello di StockExcel.
 */
class InventoryExcel
{
    public const HEADERS = ['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Giacenza', 'Prezzo IVA incl.', 'Valore IVA incl.'];

    public function build(): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Inventario');
        $sheet->fromArray(self::HEADERS);

        $products = Product::query()
            ->where(fn ($q) => $q->sellable()->orWhere('stock_qty', '!=', 0))
            ->orderBy('name')
            ->get(['id', 'sku', 'ean', 'name', 'format', 'stock_qty', 'price_cents']);

        $row = 2;
        foreach ($products as $p) {
            $sheet->fromArray([$p->id, null, null, trim($p->name), $p->format, (float) $p->stock_qty, $p->price_cents / 100], null, "A{$row}", true);
            // Codici come testo: niente notazione scientifica o zeri iniziali persi
            $sheet->setCellValueExplicit("B{$row}", (string) $p->sku, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", (string) $p->ean, DataType::TYPE_STRING);
            $sheet->setCellValue("H{$row}", "=F{$row}*G{$row}");
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->setCellValue("D{$row}", 'Totale');
        $sheet->setCellValue("F{$row}", "=SUM(F2:F{$last})");
        $sheet->setCellValue("H{$row}", "=SUM(H2:H{$last})");
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);

        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle("F2:F{$row}")->getNumberFormat()->setFormatCode('#,##0.###');
        $sheet->getStyle("G2:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00 €');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:H{$last}");
        foreach (['A' => 9, 'B' => 16, 'C' => 16, 'D' => 55, 'E' => 12, 'F' => 11, 'G' => 15, 'H' => 15] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        return $book;
    }
}
