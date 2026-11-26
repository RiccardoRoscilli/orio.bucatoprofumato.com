<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Prezzo promo del negozio con periodo: dentro le date si vende a quel prezzo, poi torna il prezzo Orio. */
class ProductPromoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $this->product = Product::create(['sku' => 'EAU0004', 'name' => '001 - Eau de Parfum', 'price_cents' => 6000, 'site_price_cents' => 6000, 'vat_rate' => 22, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true]);
    }

    private function sell(): Sale
    {
        $id = $this->actingAs($this->admin)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertSuccessful()->json('sale.id');

        return Sale::with('items')->find($id);
    }

    public function test_promo_price_applies_only_inside_the_period(): void
    {
        $this->actingAs($this->admin)->put("/prodotti/{$this->product->id}/promo", ['promo_price' => '30', 'promo_until' => '2026-11-30'])
            ->assertSessionHasNoErrors();

        $this->assertSame(3000, $this->sell()->items->first()->unit_price_cents);
        $this->getJson('/prodotti/cerca?q=parfum')->assertJsonPath('products.0.price', '30,00')->assertJsonPath('products.0.promo', true);
        $this->getJson('/prodotti/dati?filtro=promo')->assertJsonCount(1, 'data')->assertJsonPath('data.0.promo.until', '30/11');
        $this->get("/prodotti/{$this->product->id}")->assertOk()->assertSee('Prezzo promo')->assertSee('In promo fino al 30/11/2026');

        // Ultimo giorno compreso, poi torna da sola al prezzo Orio
        Carbon::setTestNow('2026-11-30 21:00:00');
        $this->assertSame(3000, $this->sell()->items->first()->unit_price_cents);
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->assertSame(6000, $this->sell()->items->first()->unit_price_cents);
        $this->getJson('/prodotti/dati?filtro=promo')->assertJsonCount(0, 'data');
    }

    public function test_promo_scheduled_in_the_future_and_removal(): void
    {
        $this->actingAs($this->admin)->put("/prodotti/{$this->product->id}/promo", ['promo_price' => '45,50', 'promo_from' => '2026-12-20', 'promo_until' => '2026-12-24']);
        $this->assertSame(6000, $this->sell()->items->first()->unit_price_cents);

        Carbon::setTestNow('2026-12-20 09:00:00');
        $this->assertSame(4550, $this->sell()->items->first()->unit_price_cents);

        $this->put("/prodotti/{$this->product->id}/promo", ['promo_price' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->product->fresh()->promo_price_cents);
        $this->assertSame(6000, $this->sell()->items->first()->unit_price_cents);
    }

    public function test_validation_and_only_admin(): void
    {
        $url = "/prodotti/{$this->product->id}/promo";
        $this->actingAs($this->admin)->put($url, ['promo_price' => '70', 'promo_until' => '2026-11-30'])->assertSessionHasErrors('promo_price');
        $this->put($url, ['promo_price' => '30'])->assertSessionHasErrors('promo_until');
        $this->put($url, ['promo_price' => '30', 'promo_until' => '2026-11-20'])->assertSessionHasErrors('promo_until');

        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->actingAs($operator)->put($url, ['promo_price' => '30', 'promo_until' => '2026-11-30'])->assertForbidden();
        $this->assertNull($this->product->fresh()->promo_price_cents);
    }
}
