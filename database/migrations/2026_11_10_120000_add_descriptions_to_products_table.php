<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Testi della pagina prodotto di bucatoprofumato.com, per la scheda prodotto in Orio. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('categories', 500)->nullable()->after('category');
            $table->text('short_description')->nullable()->after('image_url');
            $table->text('description')->nullable()->after('short_description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['categories', 'short_description', 'description']);
        });
    }
};
