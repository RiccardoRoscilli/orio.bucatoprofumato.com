<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * I cesti arrivati da teaerp non sono sul sito: il sync notturno del catalogo li disattivava
 * e in cassa non si trovavano più. Come i prodotti creati in Orio, li esclude shop_only.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('sku', 'like', 'CESTO%')->whereNull('synced_at')
            ->update(['shop_only' => true, 'active' => true]);
    }

    public function down(): void
    {
        // Nessun ritorno: rimetterli a shop_only false li farebbe disattivare di nuovo
    }
};
