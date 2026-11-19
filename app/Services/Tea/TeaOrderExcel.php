<?php

namespace App\Services\Tea;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Ordine a TEA da file Excel, a scatole:
 * colonne sku, name, pcs_per_box, boxes. Si scrivono le scatole; i pezzi per scatola sono quelli
 * di teaerp e, dove teaerp non li ha, quelli scritti nel file (che Orio ricorda per le volte dopo).
 * A TEA va il totale dei pezzi (scatole x pezzi per scatola).
 */
class TeaOrderExcel
{
    public const HEADERS = ['sku', 'name', 'pcs_per_box', 'boxes'];

    public function __construct(private TeaOrderClient $client)
    {
    }

    /**
     * Modello con i prodotti TEA in vendita in negozio, pezzi per scatola (vuoti, in giallo, dove
     * nessuno li sa ancora) e scatole a zero. Esclusi i prodotti solo del negozio (codici ORIO-).
     *
     * @throws \RuntimeException se teaerp non da' i pezzi per scatola
     */
    public function template(): Spreadsheet
    {
        $boxes = $this->client->productBoxes();

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Ordine TEA');
        $sheet->fromArray(self::HEADERS);

        $row = 2;
        $missing = [];
        foreach (Product::sellable()->whereNotNull('sku')->where('sku', 'not like', 'ORIO-%')->orderBy('sku')->get(['sku', 'name']) as $p) {
            $pcs = $boxes[trim($p->sku)] ?? null;
            $sheet->fromArray([trim($p->sku), trim($p->name), $pcs, 0], null, "A{$row}", true);
            if ($pcs === null) {
                $missing[] = "C{$row}";
            }
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle("D1:D{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CC');
        foreach ($missing as $cell) {
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FDE2E2');
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:D{$last}");
        foreach (['A' => 20, 'B' => 60, 'C' => 12, 'D' => 10] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $validation = $sheet->getCell('D2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_WHOLE)->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
            ->setFormula1('0')->setAllowBlank(true)->setShowErrorMessage(true)
            ->setErrorTitle('Scatole')->setError('Scrivi quante scatole ordinare (numero intero, 0 = nessuna).');
        $sheet->setDataValidation("D2:D{$last}", $validation);

        return $book;
    }

    /**
     * Righe dell'ordine dal file compilato: solo quelle con scatole > 0.
     *
     * @return array{rows:list<array>, problems:list<string>, boxes:int, pieces:int}
     * @throws \RuntimeException se teaerp non da' i pezzi per scatola
     */
    public function read(UploadedFile $file): array
    {
        $rows = IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $col = $this->columns(array_shift($rows) ?? []);
        $teaBoxes = $this->client->teaBoxes();
        $boxesBySku = $this->client->productBoxes();

        $result = ['rows' => [], 'problems' => [], 'boxes' => 0, 'pieces' => 0, 'learned' => 0];
        $bySku = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $sku = trim((string) ($row[$col['sku']] ?? ''));
            $raw = trim((string) ($row[$col['boxes']] ?? ''));
            if ($sku === '' || $raw === '' || $raw === '0') {
                continue;
            }
            if (! ctype_digit($raw) || (int) $raw > 9999) {
                $result['problems'][] = "riga {$line} ({$sku}): scatole non valide «{$raw}»";
                continue;
            }
            $pcs = $col['pcs'] === null ? '' : trim((string) ($row[$col['pcs']] ?? ''));
            $bySku[$sku] = [
                'boxes' => ($bySku[$sku]['boxes'] ?? 0) + (int) $raw,
                'line' => $line,
                'pcs' => ctype_digit($pcs) && (int) $pcs > 0 && (int) $pcs <= 9999 ? (int) $pcs : ($bySku[$sku]['pcs'] ?? null),
            ];
        }

        $products = Product::whereIn('sku', array_keys($bySku))->get()->keyBy(fn ($p) => trim($p->sku));
        foreach ($bySku as $sku => ['boxes' => $boxes, 'line' => $line, 'pcs' => $filePcs]) {
            $product = $products->get($sku);
            if (! $product) {
                $result['problems'][] = "riga {$line}: {$sku} non è un prodotto di Orio";
                continue;
            }
            // Comanda teaerp; dove teaerp non ha la scatola vale il file, che Orio ricorda
            if (! isset($teaBoxes[$sku]) && $filePcs && $filePcs !== $product->pcs_per_box) {
                $product->update(['pcs_per_box' => $filePcs]);
                $boxesBySku[$sku] = $filePcs;
                $result['learned']++;
            } elseif (isset($teaBoxes[$sku]) && $filePcs && $filePcs !== $teaBoxes[$sku]) {
                $result['problems'][] = "riga {$line} ({$sku}): nel file {$filePcs} pz per scatola, TEA ne ha {$teaBoxes[$sku]}: uso {$teaBoxes[$sku]}";
            }
            $perBox = $boxesBySku[$sku] ?? 1;
            $result['rows'][] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => trim($product->name),
                'format' => $product->format,
                'stock' => $product->stock_qty,
                'stock_min' => $product->stock_min,
                'pcs_per_box' => $perBox,
                'pcs_from_tea' => isset($teaBoxes[$sku]),
                'boxes' => $boxes,
            ];
            $result['boxes'] += $boxes;
            $result['pieces'] += $boxes * $perBox;
        }

        return $result;
    }

    /** Colonne cercate per intestazione (come nel modello), altrimenti le posizioni standard. */
    private function columns(array $header): array
    {
        $find = function (array $names, int $default) use ($header) {
            foreach ($header as $i => $title) {
                if (in_array(mb_strtolower(trim((string) $title)), $names, true)) {
                    return $i;
                }
            }

            return $default;
        };

        return [
            'sku' => $find(['sku', 'codice'], 0),
            'boxes' => $find(['boxes', 'scatole', 'box'], 3),
            'pcs' => $find(['pcs_per_box', 'pezzi per scatola', 'pz per box'], 2),
        ];
    }
}
