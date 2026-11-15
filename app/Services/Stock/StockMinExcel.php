<?php

namespace App\Services\Stock;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Scorte minime da Excel: per ogni prodotto in vendita in negozio la scorta minima (sotto
 * questa giacenza il prodotto viene proposto nell'ordine a TEA) e la quantita' di riordino.
 * Cella vuota = nessuna scorta minima / riordino calcolato su quanto manca.
 */
class StockMinExcel
{
    public const HEADERS = ['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Scorta minima', 'Quantità riordino'];

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Scorte minime');
        $sheet->fromArray(self::HEADERS);

        $row = 2;
        foreach (Product::sellable()->orderBy('name')->get(['id', 'sku', 'ean', 'name', 'format', 'stock_min', 'reorder_qty']) as $p) {
            $sheet->fromArray([$p->id, $p->sku, null, trim($p->name), $p->format, $p->stock_min, $p->reorder_qty], null, "A{$row}", true);
            $sheet->setCellValueExplicit("C{$row}", (string) $p->ean, DataType::TYPE_STRING);
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle("F1:G{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CC');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:G{$last}");
        foreach (['A' => 9, 'B' => 16, 'C' => 16, 'D' => 55, 'E' => 12, 'F' => 14, 'G' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        return $book;
    }

    /** @return array{updated:int, unchanged:int, problems:list<string>} */
    public function import(UploadedFile $file): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($rows) ?? []);
        $col = fn (string $name, int $default) => ($i = array_search($name, $header, true)) === false ? $default : $i;
        $idCol = $col('id orio', 0);
        $skuCol = $col('sku', 1);
        $minCol = $col('scorta minima', 5);
        $reorderCol = $col('quantità riordino', 6);

        $result = ['updated' => 0, 'unchanged' => 0, 'problems' => []];
        foreach ($rows as $index => $row) {
            if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $line = $index + 2;
            $sku = trim((string) ($row[$skuCol] ?? ''));
            $product = Product::find((int) ($row[$idCol] ?? 0)) ?? ($sku !== '' ? Product::where('sku', $sku)->first() : null);
            if (! $product || ($sku !== '' && $product->sku !== $sku)) {
                $result['problems'][] = "riga {$line}: prodotto non trovato" . ($sku !== '' ? " ({$sku})" : '');
                continue;
            }

            $values = [];
            foreach (['stock_min' => $minCol, 'reorder_qty' => $reorderCol] as $field => $i) {
                $raw = trim(str_replace(',', '.', (string) ($row[$i] ?? '')));
                if ($raw !== '' && (! is_numeric($raw) || (float) $raw < 0)) {
                    $result['problems'][] = "riga {$line}: valore non valido «{$raw}»";
                    continue 2;
                }
                $values[$field] = $raw === '' ? null : round((float) $raw, 3);
            }

            $product->fill($values);
            $product->isDirty() ? $result['updated']++ : $result['unchanged']++;
            $product->save();
        }

        return $result;
    }
}
