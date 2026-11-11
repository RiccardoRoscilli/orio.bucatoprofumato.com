<?php

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Giacenze del negozio. Ogni variazione passa da qui e lascia un movimento con la giacenza
 * risultante. La giacenza puo' andare sotto zero: in cassa non si blocca mai una vendita, il
 * prodotto finisce tra quelli "sotto zero" da controllare.
 */
class StockService
{
    public function load(Product $product, float $qty, ?int $userId, ?string $note = null): StockMovement
    {
        return $this->move($product->id, 'carico', $qty, ['user_id' => $userId, 'note' => $note]);
    }

    /** Inventario: imposta la quantita' contata, registrando la differenza. Null se non cambia nulla. */
    public function adjust(Product $product, float $counted, ?int $userId, ?string $note = null): ?StockMovement
    {
        return DB::transaction(function () use ($product, $counted, $userId, $note) {
            $current = (float) Product::whereKey($product->id)->lockForUpdate()->value('stock_qty');
            $diff = round($counted - $current, 3);

            return $diff == 0 ? null : $this->move($product->id, 'rettifica', $diff, ['user_id' => $userId, 'note' => $note]);
        });
    }

    /** Scarico delle righe di una vendita pagata (una volta sola). */
    public function unloadSale(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->first();
            if (! $locked || $locked->stock_unloaded_at) {
                return;
            }

            foreach ($locked->items()->whereNotNull('product_id')->get() as $item) {
                $this->move($item->product_id, 'vendita', -$item->qty, ['sale_id' => $sale->id, 'user_id' => $sale->user_id]);
            }

            $locked->forceFill(['stock_unloaded_at' => now()])->saveQuietly();
            $sale->stock_unloaded_at = $locked->stock_unloaded_at;
        });
    }

    /** Vendita annullata dopo lo scarico: la merce rientra con i movimenti opposti. */
    public function restoreSale(Sale $sale, ?int $userId): void
    {
        DB::transaction(function () use ($sale, $userId) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->stock_unloaded_at) {
                return;
            }

            $unloaded = StockMovement::where('sale_id', $sale->id)->get()->groupBy('product_id');
            foreach ($unloaded as $productId => $movements) {
                $qty = -$movements->sum('qty');
                if ($qty != 0) {
                    $this->move($productId, 'storno', $qty, ['sale_id' => $sale->id, 'user_id' => $userId]);
                }
            }

            $locked->forceFill(['stock_unloaded_at' => null])->saveQuietly();
            $sale->stock_unloaded_at = null;
        });
    }

    private function move(int $productId, string $type, float $qty, array $attributes): StockMovement
    {
        return DB::transaction(function () use ($productId, $type, $qty, $attributes) {
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $after = round((float) $product->stock_qty + $qty, 3);
            $product->forceFill(['stock_qty' => $after])->saveQuietly();

            return StockMovement::create($attributes + [
                'product_id' => $productId,
                'type' => $type,
                'qty' => $qty,
                'stock_after' => $after,
            ]);
        });
    }
}
