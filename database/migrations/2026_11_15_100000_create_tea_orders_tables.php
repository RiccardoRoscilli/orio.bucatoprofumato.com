<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Riassortimento da TEA: sotto la scorta minima il prodotto viene proposto nell'ordine,
            // con la quantita' di riordino (se vuota: quanto manca per tornare alla scorta minima)
            $table->decimal('stock_min', 10, 3)->nullable()->after('stock_qty');
            $table->decimal('reorder_qty', 10, 3)->nullable()->after('stock_min');
        });

        // Ordini di acquisto inviati a TEA (teaerp): Orio tiene la copia con prezzi e stato
        Schema::create('tea_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->nullable()->unique(); // "ORIO-12", inviato a teaerp
            $table->string('status', 20)->default('bozza');        // bozza | inviato | errore
            $table->string('tea_increment_id', 30)->nullable();    // numero ordine in teaerp (orio-000001)
            $table->string('tea_status', 50)->nullable();          // stato di lavorazione in teaerp
            $table->unsignedInteger('subtotal_cents')->nullable();
            $table->unsignedInteger('tax_cents')->nullable();
            $table->unsignedInteger('total_cents')->nullable();
            $table->string('notes', 500)->nullable();
            $table->text('error')->nullable();
            $table->json('response')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tea_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tea_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 64);
            $table->string('name');
            $table->unsignedInteger('qty');
            $table->decimal('stock_at_order', 10, 3)->nullable(); // giacenza al momento dell'ordine
            $table->unsignedInteger('price_cents')->nullable();   // prezzo d'acquisto TEA (IVA esclusa)
            $table->unsignedInteger('row_total_cents')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tea_order_items');
        Schema::dropIfExists('tea_orders');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['stock_min', 'reorder_qty']);
        });
    }
};
