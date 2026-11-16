<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cassa automatica collegata dal tablet: il server prepara la richiesta, il browser la inoltra. */
class VneRelayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'orio.vne.driver' => 'browser',
            'orio.vne.host' => '192.168.69.230',
            'orio.fiscal_printer.driver' => 'fake',
            'orio.pos.driver' => 'manual',
        ]);
        Http::fake(); // il server non deve mai chiamare la VNE da sé
        $this->admin = User::create(['name' => 'Admin', 'username' => 'cassa1', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 1250, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
    }

    private function draft(): Sale
    {
        $id = $this->actingAs($this->admin)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
        ])->assertOk()->json('sale.id');

        return Sale::find($id);
    }

    public function test_cash_payment_goes_through_the_tablet(): void
    {
        $sale = $this->draft();

        // 1. Orio chiede al tablet di mandare la richiesta di pagamento alla VNE
        $relay = $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'contanti'])
            ->assertStatus(428)
            ->assertJsonPath('vne_relay.url', 'https://192.168.69.230/selfcashapi/')
            ->assertJsonPath('vne_relay.payload.tipo', 1)
            ->assertJsonPath('vne_relay.payload.importo', 2500)
            ->assertJsonPath('vne_relay.payload.opName', 'cassa1')
            ->json('vne_relay');
        $this->assertSame('bozza', $sale->fresh()->status);

        // 2. Il tablet ripete la chiamata con la risposta della VNE
        $this->postJson("/vendite/{$sale->id}/pagamento", [
            'method' => 'contanti',
            '_vne_relay' => [['request' => $relay['payload'], 'response' => ['tipo' => 1, 'req_status' => 1, 'id' => 'VNE-77']]],
        ])->assertOk()->assertJson(['state' => 'pending', 'vne_id' => 'VNE-77']);
        $this->assertSame('VNE-77', $sale->fresh()->vne_transaction_id);

        // 3. Polling: GET, la risposta viaggia nell'header
        $poll = $this->getJson("/vendite/{$sale->id}/pagamento")->assertStatus(428)->json('vne_relay.payload');
        $this->assertSame(['tipo' => 2, 'id' => 'VNE-77', 'opName' => 'cassa1'], $poll);
        $header = base64_encode(json_encode([['request' => $poll, 'response' => [
            'tipo' => 2, 'req_status' => 1, 'id' => 'VNE-77', 'payment_status' => 1,
            'payment_details' => ['amount' => 2500, 'inserted' => 3000, 'rest' => 500, 'status' => 'completed'],
        ]]]));
        $this->getJson("/vendite/{$sale->id}/pagamento", ['X-Vne-Relay' => $header])->assertOk()->assertJson(['state' => 'completed']);
        $this->assertSame('pagata', $sale->fresh()->status);

        Http::assertNothingSent();
        $this->assertDatabaseHas('vne_transactions', ['sale_id' => $sale->id, 'vne_id' => 'VNE-77', 'tipo' => 1]);
    }

    public function test_response_for_a_different_request_is_not_accepted(): void
    {
        $sale = $this->draft();

        // Risposta allegata a un importo diverso: Orio richiede di nuovo l'inoltro
        $this->postJson("/vendite/{$sale->id}/pagamento", [
            'method' => 'contanti',
            '_vne_relay' => [['request' => ['tipo' => 1, 'importo' => 1, 'opName' => 'cassa1', 'refundable' => 1, 'credit_card' => 0], 'response' => ['req_status' => 1, 'id' => 'X']]],
        ])->assertStatus(428);
        $this->assertNull($sale->fresh()->vne_transaction_id);
    }

    public function test_tablet_that_cannot_reach_the_vne_reports_the_error(): void
    {
        $sale = $this->draft();
        $payload = $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'contanti'])->json('vne_relay.payload');

        $this->postJson("/vendite/{$sale->id}/pagamento", [
            'method' => 'contanti',
            '_vne_relay' => [['request' => $payload, 'error' => 'il tablet non raggiunge la cassa automatica']],
        ])->assertStatus(502)->assertJson(['message' => 'Cassa VNE non raggiungibile: il tablet non raggiunge la cassa automatica']);
        $this->assertSame('bozza', $sale->fresh()->status);
    }

    public function test_closure_page_loads_vne_status_from_the_tablet(): void
    {
        $this->actingAs($this->admin)->get('/chiusura')->assertOk()->assertSee('Lettura dello stato dal tablet');

        $payload = $this->postJson('/chiusura/stato-cassa')->assertStatus(428)->json('vne_relay.payload');
        $this->post('/chiusura/stato-cassa', ['_vne_relay' => [['request' => $payload, 'response' => [
            'tipo' => 20, 'req_status' => 1,
            'recycler' => ['totalInRecycle' => 15000, 'stacker' => 0, 'banconota_5' => ['valore' => 500, 'quantita' => 10, 'alert' => 0]],
            'hopper' => ['totalInRecycle' => 2300, 'moneta_100' => 12, 'moneta_100_alert' => 0],
        ]]]])->assertOk()->assertSee('150,00')->assertSee('23,00');
    }

    public function test_configuration_connection_test(): void
    {
        $payload = $this->actingAs($this->admin)->postJson('/configurazione/prova-vne')->assertStatus(428)->json('vne_relay.payload');
        $this->postJson('/configurazione/prova-vne', ['_vne_relay' => [['request' => $payload, 'response' => [
            'tipo' => 20, 'req_status' => 1, 'recycler' => ['totalInRecycle' => 15000], 'hopper' => ['totalInRecycle' => 2300],
        ]]]])->assertOk()->assertJson(['ok' => true]);
    }
}
