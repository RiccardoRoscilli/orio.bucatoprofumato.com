<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Scontrino di cortesia: documento non fiscale, prodotti e quantità senza prezzi. */
class CourtesyReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Cassa', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);
    }

    private function sale(string $status = 'scontrinata'): Sale
    {
        $product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente/Lavanda è', 'price_cents' => 1250, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $sale = Sale::create(['user_id' => $this->user->id, 'document_type' => 'scontrino', 'status' => $status, 'total_cents' => 2500,
            'receipt_number' => $status === 'scontrinata' ? '0012-0034' : null, 'closed_at' => now()]);
        $sale->items()->create(['product_id' => $product->id, 'description' => $product->name, 'qty' => 2, 'unit_price_cents' => 1250, 'vat_rate' => 22]);

        return $sale;
    }

    public function test_axon_prints_a_non_fiscal_document_without_prices(): void
    {
        config(['orio.fiscal_printer.driver' => 'axon', 'orio.fiscal_printer.host' => '192.168.1.50']);
        $sale = $this->sale();

        $commands = $this->actingAs($this->user)->postJson("/vendite/{$sale->id}/cortesia")
            ->assertOk()->assertJson(['driver' => 'axon', 'base' => 'http://192.168.1.50'])->json('commands');

        $this->assertSame('7/1/20//', $commands[0]);
        $this->assertSame('7/1/2/SCONTRINO DI CORTESIA/', $commands[1]);
        $this->assertContains('7/1/1/Documento commerciale n. 0012-0034/', $commands);
        $this->assertContains('7/1/1/2 x AMMORBIDENTE-LAVANDA E/', $commands);
        $this->assertSame('m/', end($commands));
        $this->assertStringNotContainsString('12.50', implode(' ', $commands));
        foreach ($commands as $pkt) {
            $this->assertMatchesRegularExpression('#^(7/1/\d+/[^/]{0,48}/|m/)$#', $pkt);
        }
        $this->assertSame('scontrinata', $sale->fresh()->status);
    }

    public function test_fake_printer_returns_a_preview(): void
    {
        config(['orio.fiscal_printer.driver' => 'fake']);
        $sale = $this->sale();

        $url = $this->actingAs($this->user)->postJson("/vendite/{$sale->id}/cortesia")->assertOk()->json('preview_url');
        $this->get($url)->assertOk()->assertSee('SCONTRINO DI CORTESIA')->assertSee('2 x AMMORBIDENTE')->assertDontSee('12,50');
    }

    public function test_only_for_issued_receipts(): void
    {
        $sale = $this->sale('pagata');

        $this->actingAs($this->user)->postJson("/vendite/{$sale->id}/cortesia")->assertStatus(422);
    }

    public function test_sale_page_shows_the_button(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->user)->get("/vendite/{$sale->id}")->assertOk()->assertSee('Scontrino di cortesia');
    }
}
