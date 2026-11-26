<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Services\Vne\VneClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Annullo di una vendita pagata in contanti: la cassa automatica restituisce i soldi (tipo 65/66). */
class VneCashRefundTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array|\Throwable> risposte della VNE in ordine */
    private array $responses = [];

    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake']);
        $test = $this;
        $this->app->bind(VneClient::class, fn () => new class($test) extends VneClient {
            public function __construct(private $test) {}

            protected function send(array $payload): array
            {
                $this->test->sent[] = $payload['tipo'];
                $next = $this->test->nextResponse();
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        });
        $this->actingAs(User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]));
    }

    public function nextResponse(): array|\Throwable
    {
        return array_shift($this->responses) ?? ['req_status' => 1];
    }

    private function cashSale(): Sale
    {
        $sale = Sale::create(['user_id' => auth()->id(), 'status' => 'pagata', 'document_type' => 'scontrino', 'payment_method' => 'contanti', 'total_cents' => 2300, 'paid_cents' => 2300, 'paid_at' => now()]);
        $sale->payments()->create(['method' => 'contanti', 'amount_cents' => 2300, 'paid_cents' => 2300, 'status' => 'pagato', 'vne_transaction_id' => '1795712030013', 'paid_at' => now()]);

        return $sale;
    }

    public function test_cash_is_given_back_by_the_machine(): void
    {
        $sale = $this->cashSale();
        $this->responses = [
            ['tipo' => 65, 'req_status' => 1],
            ['tipo' => 66, 'req_status' => 1, 'refund_status' => 'pending', 'refunded' => 1000, 'toRefund' => 2300],
            ['tipo' => 66, 'req_status' => 1, 'refund_status' => 'completed', 'refunded' => 2300, 'toRefund' => 2300],
        ];

        $this->postJson("/vendite/{$sale->id}/storno")->assertOk()
            ->assertJson(['state' => 'done', 'cash_refund' => true, 'message' => 'Vendita annullata.']);
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'refunding']);
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'refunding']);
        $this->postJson("/vendite/{$sale->id}/storno/contanti")
            ->assertJson(['state' => 'done', 'message' => 'Vendita annullata. ' . euro(2300) . ' restituiti dalla cassa automatica.']);

        $this->assertSame([65, 66, 66], $this->sent);
        $this->assertSame(2300, $sale->payments()->first()->refunded_cents);
        $this->assertSame('annullata', $sale->fresh()->status);
    }

    public function test_partial_refund_leaves_the_rest_to_give_by_hand(): void
    {
        $sale = $this->cashSale();
        $this->responses = [
            ['tipo' => 65, 'req_status' => 1],
            ['tipo' => 66, 'req_status' => 1, 'refund_status' => 'completed', 'refunded' => 2000, 'toRefund' => 2300],
        ];

        $this->postJson("/vendite/{$sale->id}/storno");
        $this->postJson("/vendite/{$sale->id}/storno/contanti");
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'done',
            'message' => 'Vendita annullata. ' . euro(2000) . ' restituiti dalla cassa automatica. Restituisci ' . euro(300) . ' in contanti al cliente.']);
    }

    public function test_refused_refund_is_given_by_hand_without_retrying(): void
    {
        $sale = $this->cashSale();
        $this->responses = [['tipo' => 65, 'req_status' => 2, 'mess' => 165]];

        $this->postJson("/vendite/{$sale->id}/storno");
        // La cassa rifiuta subito (es. rimborsi non abilitati): la parte si chiude e va data a mano
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'done'])
            ->assertJsonFragment(['message' => 'Vendita annullata. Restituisci ' . euro(2300) . ' in contanti al cliente. La cassa automatica non ha restituito i contanti: Cassa VNE: Pagamento non rimborsabile o già rimborsato (codice 165).']);
        $this->assertSame([65], $this->sent);
        $this->get("/vendite/{$sale->id}")->assertDontSee('Restituisci i contanti dalla cassa automatica');
    }

    public function test_unreachable_machine_keeps_the_refund_open_to_retry(): void
    {
        $sale = $this->cashSale();
        $this->responses = [new \RuntimeException('timeout')];

        $this->postJson("/vendite/{$sale->id}/storno");
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'unreachable']);
        $this->get("/vendite/{$sale->id}")->assertSee('Restituisci i contanti dalla cassa automatica');

        // Al nuovo tentativo non riparte il rimborso: si controlla quello eventualmente avviato
        $this->responses = [['tipo' => 66, 'req_status' => 1, 'refund_status' => 'completed', 'refunded' => 2300, 'toRefund' => 2300]];
        $this->postJson("/vendite/{$sale->id}/storno/contanti")->assertJson(['state' => 'done']);
        $this->assertSame([65, 66], $this->sent);
    }

    public function test_sales_voided_before_the_update_do_not_offer_the_refund(): void
    {
        $sale = $this->cashSale();
        $sale->update(['status' => 'annullata']);
        $migration = require database_path('migrations/2026_11_26_180000_add_cash_refund_to_sale_payments.php');
        $migration->down();
        $migration->up();

        $this->get("/vendite/{$sale->id}")->assertOk()->assertDontSee('Restituisci i contanti dalla cassa automatica');
    }
}
