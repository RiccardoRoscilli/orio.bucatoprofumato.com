<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('ean')->nullable()->index();
            $table->string('name');
            $table->string('format')->nullable();
            $table->string('category')->nullable();
            $table->string('image_url')->nullable();
            // Prezzo di vendita su bucatoprofumato.com, aggiornato a ogni sincronizzazione
            $table->unsignedInteger('site_price_cents')->nullable();
            // Prezzo di vendita in cassa: copiato dal sito alla creazione, poi gestito in orio
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedTinyInteger('vat_rate')->default(22);
            // Abilitato sul sito (Status = Enabled): solo questi si vendono in cassa
            $table->boolean('active')->default(true)->index();
            $table->boolean('in_stock')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('sale_id')->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('sku');
        });
        Schema::dropIfExists('products');
    }
};
