<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake']);

        $this->operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 1000, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
    }

    private function cardSale(float $qty): Sale
    {
        $sale = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => $qty]],
        ])->assertOk()->json('sale');

        $this->postJson("/vendite/{$sale['id']}/pagamento", ['method' => 'pos'])->assertOk();

        return Sale::find($sale['id']);
    }

    public function test_operator_loads_goods_and_paid_sale_unloads_them_once(): void
    {
        $this->actingAs($this->operator)->postJson('/magazzino/carico', [
            'items' => [['product_id' => $this->product->id, 'qty' => 5]],
            'note' => 'DDT 1',
        ])->assertOk();
        $this->assertSame(5.0, $this->product->fresh()->stock_qty);

        // Bozza: nessuno scarico
        $this->postJson('/vendite', ['document_type' => 'scontrino', 'items' => [['product_id' => $this->product->id, 'qty' => 2]]])->assertOk();
        $this->assertSame(5.0, $this->product->fresh()->stock_qty);

        $sale = $this->cardSale(2);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);

        // Emissione dello scontrino: niente doppio scarico
        $this->postJson("/vendite/{$sale->id}/scontrino")->assertOk();
        $this->assertSame('scontrinata', $sale->fresh()->status);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_sale_without_stock_goes_below_zero_and_cancel_restores_it(): void
    {
        $sale = $this->cardSale(2);
        $this->assertSame(-2.0, $this->product->fresh()->stock_qty);

        $this->actingAs($this->operator)->post("/vendite/{$sale->id}/annulla")->assertRedirect();
        $this->assertSame('annullata', $sale->fresh()->status);
        $this->assertSame(0.0, $this->product->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', ['sale_id' => $sale->id, 'type' => 'storno', 'qty' => 2]);
    }

    public function test_only_admin_can_adjust_inventory(): void
    {
        $this->actingAs($this->operator)->postJson('/magazzino/inventario', ['product_id' => $this->product->id, 'counted' => 7])->assertForbidden();

        $this->actingAs($this->admin)->postJson('/magazzino/inventario', ['product_id' => $this->product->id, 'counted' => 7])->assertOk();
        $this->assertSame(7.0, $this->product->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', ['type' => 'rettifica', 'qty' => 7, 'stock_after' => 7]);

        // Stessa quantita': nessun movimento
        $this->postJson('/magazzino/inventario', ['product_id' => $this->product->id, 'counted' => 7])->assertOk();
        $this->assertDatabaseCount('stock_movements', 1);

        $this->get('/magazzino')->assertOk()->assertSee('Rettifica inventario');
        $this->get('/prodotti/' . $this->product->id)->assertOk()->assertSee('In negozio: 7');
    }
}
