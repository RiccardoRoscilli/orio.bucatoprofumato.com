<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductHtml;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;

/**
 * Sincronizza i prodotti dall'export CSV di bucatoprofumato.com (lo stesso usato da emmycasa).
 * Come in emmycasa il prezzo in cassa viene copiato dal sito solo alla creazione; dopo resta
 * gestito in orio. Il prezzo del sito viene pero' sempre aggiornato in site_price_cents, cosi'
 * le differenze compaiono in "Prezzi da rivedere" e si possono allineare con un clic.
 */
class BucatoProductSyncService
{
    /**
     * @return array{created: int, updated: int, deactivated: int, price_changes: int}
     */
    public function sync(): array
    {
        $response = Http::timeout(120)->withUserAgent('orio-sync')->get(config('orio.bucato.products_csv_url'));

        if (! $response->successful()) {
            throw new \RuntimeException('Download del catalogo da bucatoprofumato.com non riuscito (HTTP ' . $response->status() . ')');
        }

        $csv = Reader::createFromString($response->body());
        $csv->setHeaderOffset(0);

        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'price_changes' => 0];
        $seen = [];
        $now = now();

        foreach ($csv->getRecords() as $record) {
            $sku = trim($record['sku'] ?? '');
            if ($sku === '' || ! is_numeric($record['Price'] ?? null)) {
                continue;
            }
            $seen[] = $sku;

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

            $product = Product::where('sku', $sku)->first();

            if (! $product) {
                Product::create($attributes + ['sku' => $sku, 'price_cents' => $sitePrice]);
                $stats['created']++;
                continue;
            }

            if ($product->site_price_cents !== null && $product->site_price_cents !== $sitePrice) {
                $stats['price_changes']++;
            }
            $product->update($attributes);
            $stats['updated']++;
        }

        // Spariti dall'export: non piu' vendibili in cassa (restano per lo storico vendite)
        $stats['deactivated'] = Product::whereNotIn('sku', $seen)->where('active', true)->update(['active' => false]);

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
