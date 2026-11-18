<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDT di TEA per gli ordini di riassortimento (letti da teaerp) e spunta della merce arrivata:
 * ogni riga spuntata carica in magazzino la quantita' ricevuta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tea_ddts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tea_order_id')->constrained()->cascadeOnDelete();
            $table->string('ddt_number', 60);
            $table->date('issued_at')->nullable();
            // Carico confermato: tutte le righe spuntate e chiuse, niente piu' correzioni
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['tea_order_id', 'ddt_number']);
        });

        Schema::create('tea_ddt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tea_ddt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained();
            $table->string('sku', 64)->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('pcs_per_box')->default(1); // la spunta si fa a scatole intere
            $table->decimal('qty_shipped', 12, 3);
            $table->decimal('qty_received', 12, 3)->nullable(); // null = non ancora spuntata
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tea_ddt_items');
        Schema::dropIfExists('tea_ddts');
    }
};
