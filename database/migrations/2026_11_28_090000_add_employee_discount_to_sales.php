<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sconto dipendenti del centro commerciale (tesserino mostrato in cassa): la vendita ricorda la
 * percentuale applicata; ogni riga tiene il prezzo di listino accanto a quello scontato incassato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('cashier_id');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedInteger('list_price_cents')->nullable()->after('unit_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('list_price_cents'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
    }
};
