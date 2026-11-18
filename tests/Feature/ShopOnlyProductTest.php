<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOnlyProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_and_edits_a_shop_only_product(): void
    {
        config(['orio.fiscal_printer.departments' => [22 => 1, 10 => 2]]);
        $admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        Product::create(['sku' => 'SITO1', 'ean' => '8001234567890', 'name' => 'Dal sito', 'price_cents' => 1000, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);

        $this->actingAs($operator)->get('/prodotti/nuovo')->assertForbidden();

        $this->actingAs($admin)->get('/prodotti/nuovo')->assertOk()->assertSee('Nuovo prodotto solo negozio');
        $this->post('/prodotti', ['name' => 'Shopper grande', 'price' => '0,50', 'vat_rate' => 22, 'ean' => '8001234567890'])
            ->assertSessionHasErrors(['ean' => 'Codice a barre già usato da «Dal sito».']);
        $this->post('/prodotti', ['name' => 'Shopper grande', 'price' => '0,50', 'vat_rate' => 4])->assertSessionHasErrors('vat_rate');

        $this->post('/prodotti', ['name' => 'Shopper grande', 'price' => '0,50', 'vat_rate' => 22])->assertRedirect();
        $shopper = Product::where('name', 'Shopper grande')->firstOrFail();
        $this->assertSame('ORIO-0001', $shopper->sku);
        $this->assertSame(50, $shopper->price_cents);
        $this->assertTrue($shopper->shop_only && $shopper->active && $shopper->sold_in_shop);
        $this->assertNull($shopper->site_price_cents);

        $this->post('/prodotti', ['name' => 'Shopper piccola', 'price' => '0.3', 'vat_rate' => 22, 'ean' => '2000000000015'])->assertRedirect();
        $this->assertSame('ORIO-0002', Product::where('ean', '2000000000015')->value('sku'));

        // In cassa si trova subito
        $this->actingAs($operator)->getJson('/prodotti/cerca?q=shopper')->assertJsonCount(2, 'products');
        $this->getJson('/prodotti/barcode/2000000000015')->assertOk()->assertJsonPath('products.0.description', 'Shopper piccola');

        $this->actingAs($admin)->get("/prodotti/{$shopper->id}")->assertOk()->assertSee('Modifica')->assertDontSee('Cerca sul sito');
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->put("/prodotti/{$shopper->id}", ['name' => 'Shopper grande carta', 'price' => '0,60', 'vat_rate' => 22, 'sku' => '',
            'image' => \Illuminate\Http\UploadedFile::fake()->image('shopper.jpg', 600, 600)])->assertRedirect();
        $shopper->refresh();
        $this->assertSame(['Shopper grande carta', 60, 'ORIO-0001'], [$shopper->name, $shopper->price_cents, $shopper->sku]);
        $this->assertStringStartsWith('/storage/products/', $shopper->image_url);
        $file = 'products/' . basename($shopper->image_url);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($file);
        $this->get("/prodotti/{$shopper->id}")->assertSee($shopper->image_url);

        // Foto tolta: anche il file se ne va
        $this->put("/prodotti/{$shopper->id}", ['name' => 'Shopper grande carta', 'price' => '0,60', 'vat_rate' => 22, 'remove_image' => 1])->assertRedirect();
        $this->assertNull($shopper->fresh()->image_url);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($file);
        $this->put("/prodotti/{$shopper->id}", ['name' => 'X', 'price' => '1', 'vat_rate' => 22,
            'image' => \Illuminate\Http\UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');

        // I prodotti del sito non si modificano da qui
        $this->get('/prodotti/' . Product::where('sku', 'SITO1')->value('id') . '/modifica')->assertNotFound();
    }
}
