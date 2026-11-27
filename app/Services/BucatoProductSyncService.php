<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductHtml;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;

/**
 * Aggiunge i prodotti nuovi dall'export CSV di bucatoprofumato.com. Quelli già in Orio non
 * vengono più toccati (prezzo, dati, attivo/disattivo): il sito va per conto suo e il
 * catalogo del negozio si gestisce in Orio. Il prezzo in cassa parte da quello del sito.
 */
class BucatoProductSyncService
{
    /**
     * @return array{created: int}
     */
    public function sync(): array
    {
        $response = Http::timeout(config('orio.bucato.timeout', 600))->withUserAgent('orio-sync')->get(config('orio.bucato.products_csv_url'));

        if (! $response->successful()) {
            throw new \RuntimeException('Download del catalogo da bucatoprofumato.com non riuscito (HTTP ' . $response->status() . ')');
        }

        $csv = Reader::createFromString($response->body());
        $csv->setHeaderOffset(0);

        $stats = ['created' => 0];
        $now = now();

        foreach ($csv->getRecords() as $record) {
            $sku = trim($record['sku'] ?? '');
            if ($sku === '' || ! is_numeric($record['Price'] ?? null)) {
                continue;
            }

            $sitePrice = (int) round((float) $record['Price'] * 100);
            $attributes = [
                'ean' => trim($record['ean'] ?? '') ?: (preg_match('/^\d{8,14}$/', $sku) ? $sku : null),
                'name' => trim($record['Name'] ?? $sku),
                'format' => trim($record['Quantity'] ?? '') ?: null,
                'category' => $this->mainCategory($record['Category'] ?? ''),
                'categories' => $this->categories($record['Category'] ?? '') ?: null,
                'image_url' => trim($record['Image'] ?? '') ?: null,
                'short_description' => ProductHtml::clean($record['ShortDescription'] ?? null),
                'description' => ProductHtml::clean($record['Description'] ?? null),
                'site_price_cents' => $sitePrice,
                'active' => ($record['Status'] ?? '') === 'Enabled',
                'in_stock' => ($record['In Stock'] ?? '') === 'Yes',
                'synced_at' => $now,
            ];

            if (Product::where('sku', $sku)->exists()) {
                continue;
            }
            Product::create($attributes + ['sku' => $sku, 'price_cents' => $sitePrice]);
            $stats['created']++;
        }

        return $stats;
    }

    /** "Tutti i prodotti, Per il Bucato, Spray" -> "Per il Bucato" */
    private function mainCategory(string $categories): ?string
    {
        return explode(', ', $this->categories($categories))[0] ?: null;
    }

    /** Categorie senza "Tutti i prodotti" e senza doppioni: "Per il Bucato, Spray" */
    private function categories(string $categories): string
    {
        $parts = array_filter(array_map('trim', explode(',', $categories)), fn ($c) => $c !== '' && $c !== 'Tutti i prodotti');

        return mb_substr(implode(', ', array_unique($parts)), 0, 500);
    }
}
