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
 * di teaerp, e a TEA va il totale dei pezzi (scatole x pezzi per scatola).
 */
class TeaOrderExcel
{
    public const HEADERS = ['sku', 'name', 'pcs_per_box', 'boxes'];

    public function __construct(private TeaOrderClient $client)
    {
    }

    /**
     * Modello con i prodotti in vendita in negozio che TEA vende a scatole (solo
     * quelli ordinabili), pezzi per scatola da teaerp e scatole a zero. Gli altri si ordinano a
     * pezzi con la ricerca nella pagina.
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
        foreach (Product::sellable()->whereNotNull('sku')->orderBy('sku')->get(['sku', 'name']) as $p) {
            if (! isset($boxes[trim($p->sku)])) {
                continue;
            }
            $sheet->fromArray([trim($p->sku), trim($p->name), $boxes[trim($p->sku)], 0], null, "A{$row}", true);
            $row++;
        }
        $last = max(2, $row - 1);

        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle("D1:D{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CC');
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
        $boxesBySku = $this->client->productBoxes();

        $result = ['rows' => [], 'problems' => [], 'boxes' => 0, 'pieces' => 0];
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
            $bySku[$sku] = ['boxes' => ($bySku[$sku]['boxes'] ?? 0) + (int) $raw, 'line' => $line];
        }

        $products = Product::whereIn('sku', array_keys($bySku))->get()->keyBy(fn ($p) => trim($p->sku));
        foreach ($bySku as $sku => ['boxes' => $boxes, 'line' => $line]) {
            $product = $products->get($sku);
            if (! $product) {
                $result['problems'][] = "riga {$line}: {$sku} non è un prodotto di Orio";
                continue;
            }
            $perBox = $boxesBySku[$sku] ?? 1; // senza scatola in TEA: si ordina a pezzo
            $result['rows'][] = [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => trim($product->name),
                'format' => $product->format,
                'stock' => $product->stock_qty,
                'stock_min' => $product->stock_min,
                'pcs_per_box' => $perBox,
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
        ];
    }
}
