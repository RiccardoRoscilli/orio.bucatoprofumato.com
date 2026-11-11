<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Giacenze del negozio: quantita' per prodotto e registro dei movimenti (carichi, vendite, rettifiche). */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock_qty', 10, 3)->default(0)->after('in_stock');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // carico | vendita | storno | rettifica
            $table->decimal('qty', 10, 3); // con segno: + entra, - esce
            $table->decimal('stock_after', 10, 3);
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('stock_unloaded_at')->nullable()->after('closed_at');
        });

        // Le giacenze partono dall'inventario: le vendite gia' esistenti non scaricano nulla
        DB::table('sales')->where('status', '!=', 'bozza')->update(['stock_unloaded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('stock_unloaded_at'));
        Schema::dropIfExists('stock_movements');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('stock_qty'));
    }
};
