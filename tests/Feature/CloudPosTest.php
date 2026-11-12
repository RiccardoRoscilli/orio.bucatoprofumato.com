<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudPosTest extends TestCase
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
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 1250, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $this->product->forceFill(['stock_qty' => 3])->save();
    }

    private function draft(): Sale
    {
        $sale = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->assertOk()->json('sale');

        return Sale::find($sale['id']);
    }

    private function fakeMultiSafepay(array $statuses): void
    {
        Http::fake(function (Request $r) use (&$statuses) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/cancel')) {
                return Http::response(['success' => true, 'data' => ['status' => 'cancelled']]);
            }
            if ($r->method() === 'GET') {
                $status = array_shift($statuses) ?? 'initialized';

                return Http::response(['success' => true, 'data' => ['status' => $status, 'transaction_id' => $status === 'completed' ? 1171875050 : null]]);
            }

            return Http::response(['success' => true, 'data' => ['order_id' => $r['order_id'], 'events_token' => 't']]);
        });
    }

    public function test_card_payment_is_sent_to_terminal_and_completes(): void
    {
        $this->fakeMultiSafepay(['initialized', 'declined', 'completed']);
        $sale = $this->draft();

        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertOk()->assertJson(['state' => 'pending']);
        $sale->refresh();
        $this->assertSame('in_pagamento', $sale->status);
        $this->assertStringStartsWith("orio-{$sale->id}-", $sale->pos_order_id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), 'https://api.multisafepay.com/v1/json/orders?api_key=group-key')
            && $r['amount'] === 2500
            && $r['gateway_info']['terminal_id'] === '000000MB'
            && $r['type'] === 'redirect');

        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'pending']);
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'declined']);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);

        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'completed']);
        $sale->refresh();
        $this->assertSame('pagata', $sale->status);
        $this->assertSame(2500, $sale->paid_cents);
        $this->assertSame('1171875050', $sale->pos_transaction_id);
        $this->assertSame(1.0, $this->product->fresh()->stock_qty);
        $this->assertDatabaseHas('pos_transactions', ['sale_id' => $sale->id, 'action' => 'start']);
    }

    public function test_cancelled_or_expired_payment_reopens_the_sale(): void
    {
        $this->fakeMultiSafepay(['initialized', 'expired']);
        $sale = $this->draft();
        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertOk();

        $this->postJson("/vendite/{$sale->id}/pagamento/annulla")->assertOk()->assertJson(['message' => 'Annullamento inviato al POS…']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/cancel?api_key=group-key'));

        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'pending']);
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'cancelled']);
        $sale->refresh();
        $this->assertSame('bozza', $sale->status);
        $this->assertNull($sale->pos_order_id);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);

        // Si puo' riprovare con un nuovo ordine
        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertOk()->assertJson(['state' => 'pending']);
    }

    public function test_unreachable_terminal_keeps_sale_open_and_allows_manual_confirmation(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'error_code' => 1032, 'error_info' => 'Invalid API key'], 403)]);
        $sale = $this->draft();

        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertStatus(502)
            ->assertJson(['message' => 'Il POS ha rifiutato la richiesta: Invalid API key']);
        $this->assertSame('bozza', $sale->fresh()->status);

        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos', 'manual' => true])->assertOk()->assertJson(['state' => 'completed']);
        $this->assertSame('pagata', $sale->fresh()->status);
    }

    public function test_manual_driver_keeps_current_behaviour(): void
    {
        config(['orio.pos.driver' => 'manual']);
        Http::fake();
        $sale = $this->draft();

        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertOk()->assertJson(['state' => 'completed']);
        Http::assertNothingSent();
    }

    public function test_fake_terminal_follows_chosen_scenario(): void
    {
        config(['orio.pos.driver' => 'fake']);
        $sale = $this->draft();
        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos'])->assertOk();
        $this->postJson("/vendite/{$sale->id}/pagamento/scenario", ['scenario' => 'declined'])->assertOk();
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'pending']);

        $this->travel(7)->seconds();
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'declined']);
    }
}
