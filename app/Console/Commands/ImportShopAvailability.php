<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use League\Csv\Reader;

/**
 * Importa le scelte della titolare "Vendere in negozio? SI/NO" dal file Excel dei prodotti
 * (salvato in CSV). Le righe sono abbinate per "ID Orio". Senza --apply mostra solo l'anteprima.
 */
class ImportShopAvailability extends Command
{
    protected $signature = 'products:import-shop {file : CSV con le colonne "ID Orio" e "Vendere in negozio?"} {--apply : applica le modifiche}';

    protected $description = 'Importa da file quali prodotti si vendono in negozio';

    public function handle(): int
    {
        $csv = Reader::createFromPath($this->argument('file'));
        $csv->setDelimiter(str_contains(file_get_contents($this->argument('file'), length: 2000), ';') ? ';' : ',');
        $csv->setHeaderOffset(0);

        $choices = [];
        $skus = [];
        $problems = [];
        foreach ($csv->getRecords() as $line => $record) {
            $id = (int) ($record['ID Orio'] ?? 0);
            $raw = mb_strtoupper(trim((string) ($record['Vendere in negozio?'] ?? '')));
            $value = match (true) {
                in_array($raw, ['SÌ', 'SI', 'SÍ', 'S', 'YES', 'X', '1'], true) => true,
                in_array($raw, ['NO', 'N', '0'], true) => false,
                default => null,
            };

            if (! $id) {
                $problems[] = "riga {$line}: manca l'ID Orio";
            } elseif ($value === null) {
                $problems[] = "riga {$line} (ID {$id}): valore non valido «{$raw}»";
            } else {
                $choices[$id] = $value;
                $skus[$id] = trim((string) ($record['SKU'] ?? ''));
            }
        }

        $products = Product::whereIn('id', array_keys($choices))->get()->keyBy('id');
        foreach (array_diff(array_keys($choices), $products->keys()->all()) as $missing) {
            $problems[] = "ID {$missing}: prodotto non trovato in Orio";
            unset($choices[$missing]);
        }

        // L'ID e' quello del database da cui e' stato fatto l'export: se lo SKU non coincide la riga e' di un altro prodotto
        foreach ($choices as $id => $value) {
            if ($skus[$id] !== '' && $skus[$id] !== $products[$id]->sku) {
                $problems[] = "ID {$id}: nel file SKU {$skus[$id]}, in Orio {$products[$id]->sku} - riga saltata";
                unset($choices[$id]);
            }
        }

        $changes = collect($choices)->filter(fn ($value, $id) => $products[$id]->sold_in_shop !== $value);
        $notInFile = Product::whereNotIn('id', array_keys($choices))->count();

        $this->info(sprintf('Nel file: %d SÌ, %d NO. Da cambiare: %d (%d diventano NO, %d diventano SÌ).',
            count(array_filter($choices)), count($choices) - count(array_filter($choices)),
            $changes->count(), $changes->reject(fn ($v) => $v)->count(), $changes->filter()->count()));
        if ($notInFile) {
            $this->warn("Prodotti in Orio non presenti nel file (restano come sono): {$notInFile}");
        }
        foreach ($problems as $problem) {
            $this->warn($problem);
        }
        foreach ($changes as $id => $value) {
            $this->line(($value ? '  + SÌ ' : '  - NO ') . trim($products[$id]->name) . " ({$products[$id]->sku})");
        }

        if (! $this->option('apply')) {
            $this->comment('Anteprima: nessuna modifica. Rilancia con --apply per applicare.');

            return self::SUCCESS;
        }

        foreach ($changes as $id => $value) {
            $products[$id]->update(['sold_in_shop' => $value]);
        }
        $this->info("Applicate {$changes->count()} modifiche.");

        return self::SUCCESS;
    }
}
