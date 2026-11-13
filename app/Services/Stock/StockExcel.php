<?php

namespace App\Services\Stock;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Magazzino da file Excel (come il riallineamento di emmycasa): si scarica il modello con i
 * prodotti in vendita in negozio, si scrive nell'unica colonna "Quantità" quanti pezzi ci sono
 * in negozio e lo si ricarica. È un azzeramento completo: per ogni prodotto del file la giacenza
 * diventa la quantità scritta (cella vuota = 0); la differenza resta come movimento di rettifica.
 * I prodotti che non compaiono nel file restano come sono.
 */
class StockExcel
{
    // Una sola colonna di quantità, per non confondere giacenza attuale e pezzi contati
    public const HEADERS = ['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Quantità'];

    public function __construct(private StockService $stock)
    {
    }

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Magazzino');
        $sheet->fromArray(self::HEADERS);

        $products = Product::sellable()->orderBy('name')->get(['id', 'sku', 'ean', 'name', 'format']);
        $row = 2;
        foreach ($products as $p) {
            // Quantità a 0: è il valore che il prodotto avrà se non viene cambiata
            $sheet->fromArray([$p->id, $p->sku, null, trim($p->name), $p->format, 0], null, "A{$row}", true);
            // EAN come testo, altrimenti Excel lo mostra in notazione scientifica
            $sheet->setCellValueExplicit("C{$row}", (string) $p->ean, DataType::TYPE_STRING);
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle("F1:F{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CC');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:F{$last}");
        foreach (['A' => 9, 'B' => 16, 'C' => 16, 'D' => 55, 'E' => 12, 'F' => 12] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $validation = $sheet->getCell('F2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setFormula1('0')->setAllowBlank(true)->setShowErrorMessage(true)
            ->setErrorTitle('Quantità')->setError('Scrivi quanti pezzi ci sono in negozio (0 o più).');
        $sheet->setDataValidation("F2:F{$last}", $validation);

        return $book;
    }

    /**
     * @return array{changed:int, unchanged:int, rows:int, problems:list<string>}
     */
    public function import(UploadedFile $file, ?int $userId, ?string $note): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $columns = $this->columns(array_shift($rows) ?? []);

        $result = ['changed' => 0, 'unchanged' => 0, 'rows' => 0, 'problems' => []];
        $todo = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            // Riga completamente vuota (es. in fondo al foglio): non è un prodotto
            if (! array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }

            $raw = trim((string) ($row[$columns['qty']] ?? ''));
            $qty = $raw === '' ? '0' : str_replace(',', '.', $raw);
            if (! is_numeric($qty) || (float) $qty < 0 || (float) $qty > 99999) {
                $result['problems'][] = "riga {$line}: quantità non valida «{$raw}»";
                continue;
            }

            $product = $this->findProduct($row, $columns);
            if (is_string($product)) {
                $result['problems'][] = "riga {$line}: {$product}";
                continue;
            }

            $todo[$product->id] = [$product, round((float) $qty, 3)];
        }
        $result['rows'] = count($todo);

        DB::transaction(function () use ($todo, $userId, $note, &$result) {
            foreach ($todo as [$product, $qty]) {
                $movement = $this->stock->adjust($product, $qty, $userId, $note);
                $movement ? $result['changed']++ : $result['unchanged']++;
            }
        });

        return $result;
    }

    /** Posizione delle colonne cercata per intestazione, così l'ordine nel file può cambiare. */
    private function columns(array $header): array
    {
        $find = function (array $names, ?int $default) use ($header) {
            foreach ($header as $i => $title) {
                if (in_array(mb_strtolower(trim((string) $title)), $names, true)) {
                    return $i;
                }
            }

            return $default;
        };

        return [
            'id' => $find(['id orio', 'id'], null),
            'sku' => $find(['sku', 'codice'], null),
            'ean' => $find(['ean', 'barcode', 'codice a barre'], null),
            'qty' => $find(['quantità', 'quantita', 'qta', 'q.tà', 'pezzi'], 5),
        ];
    }

    /** Abbina la riga per ID Orio (verificato con lo SKU), poi per SKU, poi per EAN. */
    private function findProduct(array $row, array $columns): Product|string
    {
        $cell = fn (string $key) => $columns[$key] === null ? '' : trim((string) ($row[$columns[$key]] ?? ''));
        $id = (int) $cell('id');
        $sku = $cell('sku');
        $ean = $cell('ean');

        if ($id && ($product = Product::find($id))) {
            if ($sku !== '' && $product->sku !== null && $sku !== $product->sku) {
                return "l'ID {$id} in Orio è {$product->sku}, nel file c'è {$sku}: riga saltata";
            }

            return $product;
        }
        if ($sku !== '' && ($product = Product::where('sku', $sku)->first())) {
            return $product;
        }
        if ($ean !== '' && ($product = Product::where('ean', $ean)->first())) {
            return $product;
        }

        return 'prodotto non trovato (' . collect([$id ? "ID {$id}" : null, $sku, $ean])->filter()->implode(' · ') . ')';
    }
}
