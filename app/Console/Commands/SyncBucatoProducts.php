<?php

namespace App\Console\Commands;

use App\Services\BucatoProductSyncService;
use Illuminate\Console\Command;

class SyncBucatoProducts extends Command
{
    protected $signature = 'products:sync';

    protected $description = 'Aggiunge i prodotti nuovi da bucatoprofumato.com (quelli già in Orio non si toccano)';

    public function handle(BucatoProductSyncService $service): int
    {
        $stats = $service->sync();

        $this->info("Prodotti nuovi dal sito: {$stats['created']}.");

        return self::SUCCESS;
    }
}
