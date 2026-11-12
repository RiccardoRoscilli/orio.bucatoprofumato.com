<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Prodotto creato in Orio e venduto solo in negozio (es. cofanetti vuoti): non esiste su
            // bucatoprofumato.com, quindi la sincronizzazione non deve disattivarlo
            $table->boolean('shop_only')->default(false)->after('sold_in_shop');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shop_only');
        });
    }
};
