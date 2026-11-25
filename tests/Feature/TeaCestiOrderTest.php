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

    public function test_wrong_secret_is_rejected(): void
    {
        $this->postJson('/api/tea/cesti-orders', $this->payload(['unit_price_cents' => 3050]), ['X-Tea-Secret' => 'no'])
            ->assertForbidden();
    }
}
