<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\FiscalPrinter\AxonG100;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Pagamento a parti: due carte, oppure contanti (VNE dal tablet) + carta. */
class SplitPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private Product $product;
    /** @var list<string> stati restituiti dalle letture degli ordini MultiSafepay */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'orio.vne.driver' => 'browser',
            'orio.vne.host' => '192.168.69.230',
            'orio.fiscal_printer.driver' => 'fake',
            'orio.fiscal_printer.payment_cash' => 1,
            'orio.fiscal_printer.payment_card' => 2,
            'orio.pos.driver' => 'multisafepay',
            'orio.pos.terminals' => [
                ['id' => '000000MB', 'name' => 'SmartPOS', 'api_key' => 'pos-key', 'active' => true],
                ['id' => '00000WBP', 'name' => 'Telefono', 'api_key' => 'tap-key', 'active' => true],
            ],
        ]);
        $this->operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 5000, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $this->product->forceFill(['stock_qty' => 5])->save();

        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/refunds')) {
                return Http::response(['success' => true, 'data' => ['refund_id' => 'R1']]);
            }
            if ($r->method() === 'GET') {
                $status = array_shift($this->statuses) ?? 'initialized';

                return Http::response(['success' => true, 'data' => ['status' => $status, 'transaction_id' => $status === 'completed' ? (string) random_int(1, 999999) : null]]);
            }

            return Http::response(['success' => true, 'data' => ['order_id' => $r['order_id']]]);
        });
    }

    private function draft(int $qty = 2): Sale
    {
        $id = $this->actingAs($this->operator)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'items' => [['product_id' => $this->product->id, 'qty' => $qty]],
        ])->assertOk()->json('sale.id');

        return Sale::find($id);
    }

    private function payCard(Sale $sale, int $amount, string $terminal): void
    {
        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos', 'terminal' => $terminal, 'amount' => $amount])
            ->assertOk()->assertJson(['state' => 'pending', 'amount' => $amount]);
    }

    public function test_two_cards_pay_one_sale(): void
    {
        $sale = $this->draft(); // 100 €

        // Prima carta: 50 € sul SmartPOS
        $this->payCard($sale, 5000, '000000MB');
        $this->statuses = ['completed'];
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertOk()
            ->assertJson(['state' => 'part_completed', 'sale' => ['status' => 'bozza', 'paid_cents' => 5000]]);
        $this->assertSame(5.0, $this->product->fresh()->stock_qty); // la merce esce solo a vendita pagata

        // Il carrello e' bloccato: un salvataggio non cambia le righe gia' in parte pagate
        $this->putJson('/vendite/bozza', ['draft_id' => $sale->id, 'document_type' => 'scontrino', 'items' => [['product_id' => $this->product->id, 'qty' => 1]]])->assertOk();
        $this->assertSame(10000, $sale->fresh()->total_cents);

        // Oltre il resto non si puo'
        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos', 'amount' => 6000])->assertStatus(422);

        // Seconda carta: il resto sul telefono Tap to Pay
        $this->postJson('/vendite', ['draft_id' => $sale->id, 'document_type' => 'scontrino', 'items' => [['product_id' => $this->product->id, 'qty' => 2]]])
            ->assertOk()->assertJsonPath('parts.0.cents', 5000);
        $this->payCard($sale, 5000, '00000WBP');
        $this->statuses = ['completed'];
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertOk()->assertJson(['state' => 'completed']);

        $sale->refresh();
        $this->assertSame('pagata', $sale->status);
        $this->assertSame(10000, $sale->paid_cents);
        $this->assertSame('pos', $sale->payment_method);
        $this->assertSame(3.0, $this->product->fresh()->stock_qty);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'api_key=tap-key') && $r['amount'] === 5000 && $r['gateway_info']['terminal_id'] === '00000WBP');

        // Scontrino: un solo pagamento elettronico da 100 €
        $this->assertSame(['5/2/100.00////'], array_values(array_filter(AxonG100::receipt($sale->load('items', 'payments')), fn ($c) => str_starts_with($c, '5/'))));

        // Annullo: ogni carta rimborsata della sua parte, sul suo terminale
        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()->assertJson(['state' => 'done']);
        $this->assertSame('annullata', $sale->fresh()->status);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/refunds') && str_contains($r->url(), 'api_key=pos-key') && $r['amount'] === 5000);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/refunds') && str_contains($r->url(), 'api_key=tap-key') && $r['amount'] === 5000);
        $this->assertSame(0, $sale->payments()->whereNull('refunded_at')->count());
    }

    public function test_cash_and_card_on_the_same_receipt(): void
    {
        $sale = $this->draft(); // 100 €

        // 30 € in contanti sulla VNE (dal tablet)
        $relay = $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'contanti', 'amount' => 3000])
            ->assertStatus(428)->assertJsonPath('vne_relay.payload.importo', 3000)->json('vne_relay');
        $this->postJson("/vendite/{$sale->id}/pagamento", [
            'method' => 'contanti', 'amount' => 3000,
            '_vne_relay' => [['request' => $relay['payload'], 'response' => ['tipo' => 1, 'req_status' => 1, 'id' => 'VNE-1']]],
        ])->assertOk()->assertJson(['state' => 'pending', 'amount' => 3000]);
        $poll = $this->getJson("/vendite/{$sale->id}/pagamento")->assertStatus(428)->json('vne_relay.payload');
        $header = base64_encode(json_encode([['request' => $poll, 'response' => [
            'tipo' => 2, 'req_status' => 1, 'id' => 'VNE-1', 'payment_status' => 1,
            'payment_details' => ['amount' => 3000, 'inserted' => 5000, 'rest' => 2000, 'status' => 'completed'],
        ]]]));
        $this->getJson("/vendite/{$sale->id}/pagamento", ['X-Vne-Relay' => $header])->assertOk()
            ->assertJson(['state' => 'part_completed', 'sale' => ['paid_cents' => 3000]]);

        // 70 € con carta
        $this->payCard($sale, 7000, '000000MB');
        $this->statuses = ['completed'];
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'completed']);

        $sale->refresh()->load('items', 'payments');
        $this->assertSame('misto', $sale->payment_method);
        $this->assertSame(['contanti' => 3000, 'pos' => 7000], $sale->paidByType());
        $this->assertSame(['5/1/30.00////', '5/2/70.00////'], array_values(array_filter(AxonG100::receipt($sale), fn ($c) => str_starts_with($c, '5/'))));

        // Scheda vendita con le due parti, e chiusura cassa con ogni parte nel suo totale
        $this->get("/vendite/{$sale->id}")->assertOk()->assertSee('Contanti')->assertSee('Carta · SmartPOS')->assertSee('Contanti e carta');
        $this->get('/chiusura')->assertOk()->assertSee('30,00')->assertSee('70,00');
    }

    public function test_interrupted_split_can_be_voided_with_refund(): void
    {
        $sale = $this->draft();
        $this->payCard($sale, 4000, '000000MB');
        $this->statuses = ['completed'];
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'part_completed']);

        // La cassa riapre il pagamento diviso dopo un ricaricamento
        $this->get('/')->assertOk()->assertViewHas('initial', fn ($state) => $state['sale']['paid_cents'] === 4000 && $state['parts'][0]['cents'] === 4000);

        // L'annullo semplice della bozza non basta: c'e' una carta da rimborsare
        $this->post("/vendite/{$sale->id}/annulla")->assertStatus(422);

        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()
            ->assertJson(['state' => 'done', 'message' => 'Vendita annullata. € 40,00 rimborsati sulla carta del cliente.']);
        $this->assertSame('annullata', $sale->fresh()->status);
        $this->assertSame(5.0, $this->product->fresh()->stock_qty);
    }

    public function test_declined_or_expired_part_leaves_the_rest_to_pay(): void
    {
        $sale = $this->draft();
        $this->payCard($sale, 5000, '000000MB');
        $this->statuses = ['completed'];
        $this->getJson("/vendite/{$sale->id}/pagamento");

        // La seconda carta scade sul terminale: la parte pagata resta, si riprova il resto
        $this->payCard($sale, 5000, '00000WBP');
        $this->statuses = ['expired'];
        $this->getJson("/vendite/{$sale->id}/pagamento")->assertJson(['state' => 'cancelled', 'sale' => ['status' => 'bozza', 'paid_cents' => 5000]]);
        $this->assertSame(['pagato', 'annullato'], $sale->payments()->orderBy('id')->pluck('status')->all());

        $this->postJson("/vendite/{$sale->id}/pagamento", ['method' => 'pos', 'manual' => true])
            ->assertOk()->assertJson(['state' => 'completed', 'sale' => ['status' => 'pagata', 'paid_cents' => 10000]]);
    }
}
