<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** I cesti sono pezzi unici: quelli già venduti escono dalla cassa (da ora lo fa la vendita stessa). */
return new class extends Migration
{
    public function up(): void
    {
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNotNull('sales.stock_unloaded_at')->whereNotNull('sale_items.product_id')
            ->pluck('sale_items.product_id');

        DB::table('products')->whereIn('id', $sold)->where('sku', 'like', 'CESTO%')->update(['active' => false]);
    }

    public function down(): void
    {
    }
};
