<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TeaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeaOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $low;
    private Product $ok;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.tea.url' => 'https://teaerp.example', 'orio.tea.user' => 'orio-api@teaerp.local', 'orio.tea.password' => 'secret']);
        $this->admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $this->low = Product::create(['sku' => 'LA00012.050', 'name' => 'Ambra 500', 'price_cents' => 1500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true, 'stock_min' => 5, 'reorder_qty' => 12]);
        $this->low->forceFill(['stock_qty' => 2])->save();
        $this->ok = Product::create(['sku' => 'LA00012.010', 'name' => 'Ambra 100', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true, 'stock_min' => 3]);
        $this->ok->forceFill(['stock_qty' => 3])->save();
    }

    public function test_proposal_lists_products_below_minimum_with_reorder_qty(): void
    {
        $proposal = $this->actingAs($this->admin)->get('/ordini-tea')->assertOk()->viewData('proposal');

        $this->assertSame(['Ambra 500'], $proposal->pluck('name')->all());
        $this->assertSame(12, $proposal->first()['qty']);
    }

    public function test_order_is_sent_to_teaerp_and_prices_are_saved(): void
    {
        Http::fake(['teaerp.example/api/orio/orders' => Http::response(['ok' => true, 'created' => true, 'order' => [
            'increment_id' => 'orio-000007', 'erp_status' => 'Pending', 'subtotal' => 118.08, 'tax_amount' => 25.98, 'grand_total' => 144.06,
            'items' => [['sku' => 'LA00012.050', 'qty' => 12, 'price' => 9.84, 'row_total' => 118.08]],
        ]], 201)]);

        $this->actingAs($this->admin)->postJson('/ordini-tea', ['items' => [['product_id' => $this->low->id, 'qty' => 12]], 'notes' => 'novembre'])
            ->assertOk()->assertJson(['ok' => true]);

        $order = TeaOrder::first();
        $this->assertSame('inviato', $order->status);
        $this->assertSame('orio-000007', $order->tea_increment_id);
        $this->assertSame(14406, $order->total_cents);
        $this->assertSame(984, $order->items->first()->price_cents);
        $this->assertSame(2.0, $order->items->first()->stock_at_order);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://teaerp.example/api/orio/orders'
            && $r['reference'] === 'ORIO-' . $order->id
            && $r['items'] === [['sku' => 'LA00012.050', 'qty' => 12]]
            && $r['shipping']['city'] === 'Orio al Serio'
            && $r->hasHeader('Authorization'));
    }

    public function test_rejected_order_is_kept_and_can_be_resent_with_same_reference(): void
    {
        Http::fakeSequence('teaerp.example/*')
            ->push(['ok' => false, 'error' => ['code' => 'INVALID_ITEMS', 'message' => 'Ordine non creato.', 'details' => ['LA00012.050: nessun prezzo nel listino']]], 422)
            ->push(['ok' => true, 'created' => true, 'order' => ['increment_id' => 'orio-000008', 'erp_status' => 'Pending', 'subtotal' => 1, 'tax_amount' => 0.22, 'grand_total' => 1.22, 'items' => []]], 201);

        $this->actingAs($this->admin)->postJson('/ordini-tea', ['items' => [['product_id' => $this->low->id, 'qty' => 1]]])
            ->assertStatus(502)->assertJsonFragment(['ok' => false]);
        $order = TeaOrder::first();
        $this->assertSame('errore', $order->status);
        $this->assertStringContainsString('nessun prezzo nel listino', $order->error);

        $this->postJson("/ordini-tea/{$order->id}/invia")->assertOk();
        $this->assertSame('orio-000008', $order->fresh()->tea_increment_id);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['reference'] === 'ORIO-' . $order->id);
    }

    public function test_only_admin_can_order(): void
    {
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->actingAs($operator)->get('/ordini-tea')->assertForbidden();
    }
}
