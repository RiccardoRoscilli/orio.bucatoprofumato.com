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

    public function test_box_barcode_in_ean2_finds_the_product(): void
    {
        $operator = \App\Models\User::create(['name' => 'Op', 'username' => 'op2', 'password' => 'x', 'active' => true]);
        Product::create(['sku' => 'LA00516STSP', 'ean' => '0806812582228', 'ean2' => '0806812583560', 'name' => 'Spray per tessuti Sport', 'price_cents' => 1000, 'vat_rate' => 22, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true]);

        // Scatola letta dallo scanner, anche senza lo zero iniziale, e ricerca scritta a mano
        foreach (['0806812583560', '806812583560', '0806812582228'] as $code) {
            $this->actingAs($operator)->getJson("/prodotti/barcode/{$code}")->assertOk()->assertJsonPath('products.0.sku', 'LA00516STSP');
        }
        $this->getJson('/prodotti/cerca?q=812583560')->assertJsonPath('products.0.sku', 'LA00516STSP');
    }

    public function test_sync_only_adds_new_products(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(
            "sku,Name,Status,Quantity,ean,Category,Price,\"In Stock\"\nSITO1,Dal sito,Enabled,,,Essenze,10,Yes\nGIA,Nome sul sito,Disabled,,,Essenze,99,No\n"
        )]);
        Product::create(['sku' => 'VECCHIO', 'name' => 'Tolto dal sito', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        Product::create(['sku' => 'GIA', 'name' => 'Nome in Orio', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $orio = Product::create(['sku' => 'ORIO-COF3', 'name' => 'Cofanetto vuoto 3 posti', 'price_cents' => 100, 'vat_rate' => 22, 'active' => true, 'in_stock' => true, 'shop_only' => true]);

        $this->assertSame(['created' => 1], app(\App\Services\BucatoProductSyncService::class)->sync());

        $this->assertSame(1000, Product::where('sku', 'SITO1')->value('price_cents'));
        // Già in Orio o non più sul sito: restano come sono
        $this->assertTrue(Product::where('sku', 'VECCHIO')->value('active'));
        $gia = Product::where('sku', 'GIA')->first();
        $this->assertSame(['Nome in Orio', 500, true, true], [$gia->name, $gia->price_cents, $gia->active, $gia->in_stock]);
        $this->assertTrue($orio->fresh()->active);
    }
}
