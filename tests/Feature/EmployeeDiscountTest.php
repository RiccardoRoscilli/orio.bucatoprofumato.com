<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\FiscalPrinter\AxonG100;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Sconto dipendenti del centro commerciale: 10% sul listino di ogni riga, calcolato dal server. */
class EmployeeDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $cassa;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake']);
        $this->cassa = User::create(['name' => 'Cassa', 'username' => 'cassa', 'password' => 'x', 'active' => true]);
        $this->product = Product::create(['sku' => 'LA001', 'name' => 'Profumatore', 'price_cents' => 1490, 'vat_rate' => 22, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true]);
    }

    private function sell(bool $discount, int $qty = 2): Sale
    {
        $id = $this->actingAs($this->cassa)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'employee_discount' => $discount,
            'items' => [['product_id' => $this->product->id, 'qty' => $qty, 'price' => '0.01']],
        ])->assertSuccessful()->json('sale.id');

        return Sale::with('items')->find($id);
    }

    public function test_discount_lowers_each_unit_price_by_ten_percent(): void
    {
        $sale = $this->sell(true);

        // 14,90 - 1,49 = 13,41 a pezzo; il prezzo mandato dal browser non conta
        $this->assertSame(1341, $sale->items->first()->unit_price_cents);
        $this->assertSame(1490, $sale->items->first()->list_price_cents);
        $this->assertSame(2682, $sale->total_cents);
        $this->assertSame(298, $sale->discountCents());
        $this->assertSame(10, $sale->discount_percent);
        $this->assertStringContainsString('/13.41/', AxonG100::receipt($sale)[0]);

        $this->actingAs($this->cassa)->get("/vendite/{$sale->id}")->assertOk()
            ->assertSee('Sconto dipendenti 10%');
    }

    public function test_without_discount_the_list_price_applies(): void
    {
        $sale = $this->sell(false);

        $this->assertSame(2980, $sale->total_cents);
        $this->assertNull($sale->discount_percent);
        $this->assertSame(0, $sale->discountCents());
    }

    public function test_draft_keeps_the_discount_after_reload(): void
    {
        $this->actingAs($this->cassa)->putJson('/vendite/bozza', [
            'document_type' => 'scontrino',
            'employee_discount' => true,
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->assertOk()->assertJsonPath('total_cents', 1341);

        $this->actingAs($this->cassa)->get('/')->assertOk()
            ->assertViewHas('initial', fn ($state) => $state['employee_discount'] === true && $state['rows'][0]['price'] === '14,90');
    }

    public function test_report_counts_discounted_sales(): void
    {
        $admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $sale = $this->sell(true);
        $sale->update(['status' => 'pagata', 'paid_cents' => $sale->total_cents, 'paid_at' => now()]);

        $this->actingAs($admin)->get('/rapporto')->assertOk()
            ->assertSee('1 vendita con sconto dipendenti')->assertSee(euro(298));
    }
}
