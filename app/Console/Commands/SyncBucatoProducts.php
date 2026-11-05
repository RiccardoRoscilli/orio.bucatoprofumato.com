<?php

namespace App\Console\Commands;

use App\Services\BucatoProductSyncService;
use Illuminate\Console\Command;

class SyncBucatoProducts extends Command
{
    protected $signature = 'products:sync';

    protected $description = 'Sincronizza i prodotti e i prezzi da bucatoprofumato.com';

    public function handle(BucatoProductSyncService $service): int
    {
        $stats = $service->sync();

        $this->info("Prodotti: {$stats['created']} nuovi, {$stats['updated']} aggiornati, {$stats['deactivated']} disattivati, {$stats['price_changes']} prezzi cambiati sul sito.");

        return self::SUCCESS;
    }
}
