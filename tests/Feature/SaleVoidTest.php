<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'orio.vne.driver' => 'fake',
            'orio.fiscal_printer.driver' => 'fake',
            'orio.pos.driver' => 'multisafepay',
            'orio.pos.api_key' => 'group-key',
            'orio.pos.terminal_id' => '000000MB',
        ]);
        $this->operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $this->product->forceFill(['stock_qty' => 5])->save();
    }

    private bool $refundOk = true;

    private function receiptPaidByCard(): Sale
    {
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/refunds')) {
                return $this->refundOk ? Http::response(['success' => true, 'data' => ['refund_id' => 1]])
                    : Http::response(['success' => false, 'error_code' => 1025, 'error_info' => 'Insufficient balance'], 422);
            }
            if ($r->method() === 'GET') {
                return Http::response(['success' => true, 'data' => ['status' => 'completed', 'transaction_id' => 777]]);
            }

            return Http::response(['success' => true, 'data' => ['order_id' => $r['order_id']]]);
        });

        $id = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->json('sale.id');
        $this->postJson("/vendite/{$id}/pagamento", ['method' => 'pos'])->assertOk();
        $this->getJson("/vendite/{$id}/pagamento")->assertJson(['state' => 'completed']);
        $this->postJson("/vendite/{$id}/scontrino")->assertOk();

        return Sale::find($id);
    }

    public function test_voids_receipt_refunds_card_and_restocks(): void
    {
        $sale = $this->receiptPaidByCard();
        $this->assertSame('scontrinata', $sale->status);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);

        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()->assertJson(['state' => 'done']);

        $sale->refresh();
        $this->assertSame('annullata', $sale->status);
        $this->assertNotNull($sale->receipt_voided_at);
        $this->assertSame('ANN-' . $sale->receipt_number, $sale->void_receipt_number);
        $this->assertNotNull($sale->refunded_at);
        $this->assertSame($this->operator->id, $sale->voided_by);
        $this->assertSame(5.0, $this->product->fresh()->stock_qty);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "/orders/{$sale->pos_order_id}/refunds?api_key=group-key") && $r['amount'] === 1000);
    }

    public function test_failed_refund_keeps_fiscal_void_and_can_be_retried(): void
    {
        $sale = $this->receiptPaidByCard();
        $this->refundOk = false;

        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()->assertJson(['state' => 'refund_failed']);
        $sale->refresh();
        $this->assertSame('scontrinata', $sale->status);
        $this->assertNotNull($sale->receipt_voided_at);
        $this->assertNull($sale->refunded_at);

        $this->refundOk = true;
        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()->assertJson(['state' => 'done']);
        $this->assertSame('annullata', $sale->fresh()->status);
        $this->assertSame(2, \App\Models\PosTransaction::where('action', 'refund')->count());
    }

    public function test_axon_printer_gets_void_command_and_confirms_number(): void
    {
        config(['orio.fiscal_printer.driver' => 'axon', 'orio.fiscal_printer.host' => '192.168.1.50']);
        $sale = $this->receiptPaidByCard();
        $this->postJson("/vendite/{$sale->id}/scontrino/conferma", ['receipt_number' => '0316-0042'])->assertOk();
        $sale->forceFill(['closed_at' => '2026-11-13 10:00:00'])->save();

        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()
            ->assertJson(['state' => 'printer', 'commands' => ['+/1/13112026/316/42////']]);
        $this->assertNull($sale->fresh()->receipt_voided_at);

        $this->postJson("/vendite/{$sale->id}/storno/conferma", ['void_number' => '0316-0043'])->assertOk()->assertJson(['state' => 'done']);
        $this->assertSame('0316-0043', $sale->fresh()->void_receipt_number);
    }

    public function test_cash_sale_void_reminds_to_return_cash(): void
    {
        Http::fake();
        $id = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->json('sale.id');
        Sale::find($id)->update(['payment_method' => 'contanti', 'status' => 'pagata', 'paid_cents' => 500, 'paid_at' => now()]);
        $this->postJson("/vendite/{$id}/scontrino")->assertOk();

        $this->postJson("/vendite/{$id}/storno")->assertOk()
            ->assertJson(['state' => 'done', 'message' => 'Vendita annullata. Restituisci ' . euro(500) . ' in contanti al cliente.']);
        Http::assertNothingSent();
        $this->assertSame(5.0, $this->product->fresh()->stock_qty);
    }

    public function test_drafts_and_invoices_cannot_be_voided_here(): void
    {
        $id = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ])->json('sale.id');

        $this->postJson("/vendite/{$id}/storno")->assertStatus(422);
    }
}
