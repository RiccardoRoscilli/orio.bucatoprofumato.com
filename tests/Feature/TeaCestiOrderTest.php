<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TeaOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cesti natalizi spinti da teaerp: fattura a listino 1, vendita in cassa a prezzo pieno. */
class TeaCestiOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tea_cesti.secret' => 'segreto']);
    }

    private function payload(array $item): array
    {
        return [
            'reference' => 'VE-00100/2026',
            'tea_increment_id' => 'orio-000010',
            'ddt_number' => 'VE-00100/2026',
            'issued_at' => '2026-11-25',
            'items' => [$item + ['sku' => 'CESTO-1', 'name' => 'Cesto Natale', 'qty' => 2, 'vat_rate' => 22]],
        ];
    }

    public function test_new_basket_is_sold_at_retail_price_and_order_uses_invoiced_price(): void
    {
        $this->postJson('/api/tea/cesti-orders', $this->payload(['unit_price_cents' => 3050, 'retail_price_cents' => 4500]), ['X-Tea-Secret' => 'segreto'])
            ->assertCreated();

        $this->assertSame(4500, Product::where('sku', 'CESTO-1')->value('price_cents'));
        $order = TeaOrder::where('reference', 'VE-00100/2026')->first();
        $this->assertSame(6100, $order->total_cents);
        $this->assertSame(5000, $order->subtotal_cents);
    }

    public function test_without_retail_price_falls_back_to_invoiced_price(): void
    {
        $this->postJson('/api/tea/cesti-orders', $this->payload(['unit_price_cents' => 3050]), ['X-Tea-Secret' => 'segreto'])
            ->assertCreated();

        $this->assertSame(3050, Product::where('sku', 'CESTO-1')->value('price_cents'));
    }

    public function test_basket_is_found_searching_a_product_inside(): void
    {
        Product::create(['sku' => 'ESS-RELAX', 'ean' => '8001112223334', 'name' => 'Essenza Relax 500 ml', 'price_cents' => 1700, 'vat_rate' => 22, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true]);
        $this->postJson('/api/tea/cesti-orders', $this->payload(['unit_price_cents' => 3050, 'components' => [
            ['sku' => 'ESS-RELAX', 'name' => 'Essenza Relax 500 ml', 'qty' => 1],
            ['sku' => 'CARD-2', 'name' => 'Card profumata (2pz)', 'qty' => 2],
        ]]), ['X-Tea-Secret' => 'segreto'])->assertCreated();
        $user = \App\Models\User::create(['name' => 'Cassa', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);

        foreach (['relax', 'card profumata', 'CARD-2', '8001112223334'] as $q) {
            $skus = collect($this->actingAs($user)->getJson('/prodotti/cerca?q=' . urlencode($q))->json('products'))->pluck('sku');
            $this->assertContains('CESTO-1', $skus, "ricerca «{$q}»");
        }
        $basket = collect($this->actingAs($user)->getJson('/prodotti/cerca?q=relax')->json('products'))->firstWhere('sku', 'CESTO-1');
        $this->assertSame([['name' => 'Essenza Relax 500 ml', 'qty' => 1], ['name' => 'Card profumata (2pz)', 'qty' => 2]], $basket['components']);
        $this->actingAs($user)->get('/prodotti/' . Product::where('sku', 'CESTO-1')->value('id'))
            ->assertOk()->assertSee('Contenuto del cesto')->assertSee('Card profumata (2pz)');
        $this->assertNotContains('CESTO-1', collect($this->actingAs($user)->getJson('/prodotti/cerca?q=ammorbidente')->json('products'))->pluck('sku'));
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->postJson('/api/tea/cesti-orders', $this->payload(['unit_price_cents' => 3050]), ['X-Tea-Secret' => 'no'])
            ->assertForbidden();
    }
}
