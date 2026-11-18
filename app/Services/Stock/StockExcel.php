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
 * Inventario del magazzino da file Excel, con il modello dei trasferimenti a bucato di teaerp:
 * sku, name, pcs_per_box, boxes, extra_pcs. Si scrivono le scatole intere in "boxes" e i pezzi
 * sfusi in "extra_pcs": la giacenza diventa scatole x pezzi per scatola + pezzi sfusi. È un azzeramento completo: per ogni prodotto del file la giacenza
 * diventa la quantità scritta (cella vuota = 0); la differenza resta come movimento di rettifica.
 * I prodotti che non compaiono nel file restano come sono.
 */
class StockExcel
{
    public const HEADERS = ['sku', 'name', 'pcs_per_box', 'boxes', 'extra_pcs'];

    public function __construct(private StockService $stock, private \App\Services\Tea\TeaOrderClient $tea)
    {
    }

    public function template(): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Magazzino');
        $sheet->fromArray(self::HEADERS);

        // Pezzi per scatola di TEA (1 per i prodotti che TEA non vende a scatole o se teaerp non risponde)
        try {
            $boxes = $this->tea->productBoxes();
        } catch (\Throwable) {
            $boxes = [];
        }

        $products = Product::sellable()->whereNotNull('sku')->orderBy('sku')->get(['sku', 'name']);
        $row = 2;
        foreach ($products as $p) {
            // Scatole e sfusi a 0: è il valore che il prodotto avrà se non vengono cambiati
            $sheet->fromArray([trim($p->sku), trim($p->name), $boxes[trim($p->sku)] ?? 1, 0, 0], null, "A{$row}", true);
            $sheet->setCellValueExplicit("A{$row}", trim($p->sku), DataType::TYPE_STRING);
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle("D1:E{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CC');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:E{$last}");
        foreach (['A' => 20, 'B' => 60, 'C' => 12, 'D' => 10, 'E' => 10] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $validation = $sheet->getCell('D2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setFormula1('0')->setAllowBlank(true)->setShowErrorMessage(true)
            ->setErrorTitle('Quantità')->setError('Scrivi un numero intero, 0 o più.');
        $sheet->setDataValidation("D2:E{$last}", $validation);

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

            $qty = $this->quantity($row, $columns);
            if (is_string($qty)) {
                $result['problems'][] = "riga {$line}: {$qty}";
                continue;
            }

            $product = $this->findProduct($row, $columns);
            if (is_string($product)) {
                $result['problems'][] = "riga {$line}: {$product}";
                continue;
            }

            $todo[$product->id] = [$product, round($qty, 3)];
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

    /**
     * Pezzi della riga: scatole x pezzi per scatola + sfusi (celle vuote = 0). I file del vecchio
     * modello, con un'unica colonna di quantità, valgono ancora.
     */
    private function quantity(array $row, array $columns): float|string
    {
        $number = function (?int $col, string $label) use ($row) {
            $raw = $col === null ? '' : trim((string) ($row[$col] ?? ''));
            $value = $raw === '' ? '0' : str_replace(',', '.', $raw);

            return is_numeric($value) && (float) $value >= 0 && (float) $value <= 99999 ? (float) $value : "{$label} non valido «{$raw}»";
        };

        if ($columns['qty'] !== null) {
            return $number($columns['qty'], 'quantità');
        }

        $parts = [$number($columns['boxes'], 'boxes'), $number($columns['extra'], 'extra_pcs'), $number($columns['pcs'], 'pcs_per_box')];
        foreach ($parts as $part) {
            if (is_string($part)) {
                return $part;
            }
        }
        [$boxes, $extra, $pcs] = $parts;

        return $boxes * max(1, $pcs) + $extra;
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
            'pcs' => $find(['pcs_per_box', 'pezzi per scatola'], 2),
            'boxes' => $find(['boxes', 'scatole'], 3),
            'extra' => $find(['extra_pcs', 'pz sfusi', 'sfusi'], 4),
            // vecchio modello: una sola colonna con i pezzi
            'qty' => $find(['qtà', 'quantità', 'quantita', 'qta', 'q.tà', 'pezzi'], null),
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
