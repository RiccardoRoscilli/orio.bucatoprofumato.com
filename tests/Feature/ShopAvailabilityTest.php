<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_not_sold_in_shop_are_hidden_from_the_till(): void
    {
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $product = Product::create(['sku' => 'B1', 'ean' => '8001234567890', 'name' => 'Box da 15', 'price_cents' => 7500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);

        $this->actingAs($operator)->getJson('/prodotti/barcode/8001234567890')->assertOk();

        $this->patchJson("/prodotti/{$product->id}/negozio", ['sold_in_shop' => false])->assertForbidden();
        $this->actingAs($admin)->patchJson("/prodotti/{$product->id}/negozio", ['sold_in_shop' => false])->assertOk();

        $this->actingAs($operator)->getJson('/prodotti/barcode/8001234567890')
            ->assertNotFound()->assertJsonFragment(['message' => '«Box da 15» non è in vendita in negozio.']);
        $this->getJson('/prodotti/cerca?q=box')->assertOk()->assertJsonCount(0, 'products');
        $this->postJson('/vendite', ['document_type' => 'scontrino', 'items' => [['product_id' => $product->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_sync_keeps_products_created_in_orio(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(
            "sku,Name,Status,Quantity,ean,Category,Price,\"In Stock\"\nSITO1,Dal sito,Enabled,,,Essenze,10,Yes\n"
        )]);
        Product::create(['sku' => 'VECCHIO', 'name' => 'Tolto dal sito', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $orio = Product::create(['sku' => 'ORIO-COF3', 'name' => 'Cofanetto vuoto 3 posti', 'price_cents' => 100, 'vat_rate' => 22, 'active' => true, 'in_stock' => true, 'shop_only' => true]);

        app(\App\Services\BucatoProductSyncService::class)->sync();

        $this->assertFalse(Product::where('sku', 'VECCHIO')->value('active'));
        $this->assertTrue($orio->fresh()->active);
        $this->assertSame(100, $orio->fresh()->price_cents);
    }
}
