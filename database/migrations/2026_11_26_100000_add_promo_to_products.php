<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prezzo promo del negozio con periodo: dentro le date la cassa vende a quel prezzo, poi torna da sola al prezzo Orio
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('promo_price_cents')->nullable()->after('price_cents');
            $table->date('promo_from')->nullable()->after('promo_price_cents');
            $table->date('promo_until')->nullable()->after('promo_from');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['promo_price_cents', 'promo_from', 'promo_until']);
        });
    }
};
