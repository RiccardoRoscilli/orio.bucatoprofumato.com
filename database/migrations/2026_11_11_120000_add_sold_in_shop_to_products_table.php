<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Scelta della titolare: non tutto il catalogo del sito si vende in negozio. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sold_in_shop')->default(true)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('sold_in_shop'));
    }
};
