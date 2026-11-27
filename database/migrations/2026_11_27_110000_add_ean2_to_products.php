<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondo codice a barre, come in teaerp: su alcune scatole (es. spray tessuti) è stampato un
 * EAN diverso da quello del sito. Si legge in cassa e in magazzino come l'EAN principale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ean2', 20)->nullable()->after('ean')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ean2');
        });
    }
};
