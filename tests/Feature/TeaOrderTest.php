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
        $this->fakeBoxes();
        $proposal = $this->actingAs($this->admin)->get('/ordini-tea')->assertOk()->viewData('proposal');

        $this->assertSame(['Ambra 500'], $proposal->pluck('name')->all());
        // riordino 12 pezzi, scatola da 6: 2 scatole
        $this->assertSame([6, 2], [$proposal->first()['pcs_per_box'], $proposal->first()['boxes']]);
    }

    public function test_order_is_sent_to_teaerp_and_prices_are_saved(): void
    {
        $this->fakeBoxes();
        Http::fake(['teaerp.example/api/orio/orders' => Http::response(['ok' => true, 'created' => true, 'order' => [
            'increment_id' => 'orio-000007', 'erp_status' => 'Pending', 'subtotal' => 118.08, 'tax_amount' => 25.98, 'grand_total' => 144.06,
            'items' => [['sku' => 'LA00012.050', 'qty' => 12, 'price' => 9.84, 'row_total' => 118.08]],
        ]], 201)]);

        $this->actingAs($this->admin)->postJson('/ordini-tea', ['items' => [['product_id' => $this->low->id, 'boxes' => 2]], 'notes' => 'novembre'])
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
        $this->fakeBoxes();
        Http::fakeSequence('teaerp.example/api/orio/*')
            ->push(['ok' => false, 'error' => ['code' => 'INVALID_ITEMS', 'message' => 'Ordine non creato.', 'details' => ['LA00012.050: nessun prezzo nel listino']]], 422)
            ->push(['ok' => true, 'created' => true, 'order' => ['increment_id' => 'orio-000008', 'erp_status' => 'Pending', 'subtotal' => 1, 'tax_amount' => 0.22, 'grand_total' => 1.22, 'items' => []]], 201);

        $this->actingAs($this->admin)->postJson('/ordini-tea', ['items' => [['product_id' => $this->low->id, 'boxes' => 1]]])
            ->assertStatus(502)->assertJsonFragment(['ok' => false]);
        $order = TeaOrder::first();
        $this->assertSame('errore', $order->status);
        $this->assertStringContainsString('nessun prezzo nel listino', $order->error);

        $this->postJson("/ordini-tea/{$order->id}/invia")->assertOk();
        $this->assertSame('orio-000008', $order->fresh()->tea_increment_id);
        $this->assertSame(6, $order->items->first()->qty); // 1 scatola da 6
        Http::assertSentCount(3); // scatole da teaerp, ordine respinto, nuovo invio
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/orio/orders') && $r['reference'] === 'ORIO-' . $order->id);
    }

    public function test_operator_orders_but_only_admin_sets_minimums(): void
    {
        $this->fakeBoxes();
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->actingAs($operator)->get('/ordini-tea')->assertOk()->assertDontSee('Scorte minime da Excel');
        $this->get('/ordini-tea/scorte-minime')->assertForbidden();
        $this->patch("/prodotti/{$this->low->id}/scorta-minima", ['stock_min' => 1])->assertForbidden();
    }

    public function test_open_order_is_saved_shared_and_sent_later(): void
    {
        $this->fakeBoxes();
        Http::fake(['teaerp.example/api/orio/orders' => Http::response(['ok' => true, 'created' => true, 'order' => [
            'increment_id' => 'orio-000010', 'erp_status' => 'Pending', 'subtotal' => 1, 'tax_amount' => 0.22, 'grand_total' => 1.22, 'items' => [],
        ]], 201)]);
        $operator = User::create(['name' => 'Pamela', 'username' => 'pam', 'password' => 'x', 'active' => true]);

        // L'operatrice mette 10 scatole di Ambra 100: l'ordine resta aperto
        $this->actingAs($operator)->putJson('/ordini-tea/in-preparazione', ['items' => [['product_id' => $this->ok->id, 'boxes' => 10]], 'notes' => 'vetrina'])
            ->assertOk()->assertJsonPath('by', 'Pamela');
        $open = TeaOrder::open();
        $this->assertSame(['aperto', 10, 120], [$open->status, $open->items->first()->boxes, $open->items->first()->qty]);
        $this->assertSame(0, Http::recorded(fn ($r) => str_contains($r->url(), '/api/orio/orders'))->count());

        // Il giorno dopo l'admin la ritrova (non e' nello storico), e i sotto scorta mancanti sono a parte
        $page = $this->actingAs($this->admin)->get('/ordini-tea')->assertOk();
        $this->assertSame([[$this->ok->id, 10, 12]], $page->viewData('proposal')->map(fn ($r) => [$r['product_id'], $r['boxes'], $r['pcs_per_box']])->all());
        $this->assertSame(['Ambra 500'], $page->viewData('suggestions')->pluck('description')->all());
        $this->assertCount(0, $page->viewData('orders'));
        $this->get("/ordini-tea/{$open->id}")->assertRedirect('/ordini-tea');

        // Ne aggiunge e invia: e' lo stesso ordine, ora inviato a suo nome
        $this->postJson('/ordini-tea', ['items' => [['product_id' => $this->ok->id, 'boxes' => 10], ['product_id' => $this->low->id, 'boxes' => 2]], 'notes' => 'vetrina'])->assertOk();
        $sent = $open->fresh('items');
        $this->assertSame(['inviato', $this->admin->id, 'ORIO-' . $open->id], [$sent->status, $sent->user_id, $sent->reference]);
        $this->assertEqualsCanonicalizing([120, 12], $sent->items->pluck('qty')->all());
        $this->assertNull(TeaOrder::open());

        // Svuotato senza nota: l'ordine aperto si chiude
        $this->putJson('/ordini-tea/in-preparazione', ['items' => [['product_id' => $this->ok->id, 'boxes' => 1]]])->assertOk();
        $this->putJson('/ordini-tea/in-preparazione', ['items' => []])->assertOk()->assertJsonPath('saved_at', null);
        $this->assertNull(TeaOrder::open());
    }

    private function fakeBoxes(): void
    {
        Http::fake(['teaerp.example/api/emmycasa/product-boxes' => Http::response(['ok' => true, 'products' => [
            ['sku' => 'LA00012.050', 'box_ean' => '8000000000001', 'pezzi_per_box' => 6],
            ['sku' => 'LA00012.010', 'box_ean' => '8000000000002', 'pezzi_per_box' => 12],
        ]])]);
    }

    private function excel(array $rows): \Illuminate\Http\UploadedFile
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->getActiveSheet()->fromArray(array_merge([\App\Services\Tea\TeaOrderExcel::HEADERS], $rows), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ord') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

        return new \Illuminate\Http\UploadedFile($path, 'ordine.xlsx', null, null, true);
    }

    public function test_order_template_has_emmycasa_columns_and_tea_boxes(): void
    {
        $this->fakeBoxes();
        Product::create(['sku' => 'XX888', 'name' => 'Senza scatola', 'price_cents' => 100, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $response = $this->actingAs($this->admin)->get('/ordini-tea/modello')->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);

        $this->assertSame(['sku', 'name', 'pcs_per_box', 'boxes'], $rows[0]);
        $this->assertSame(['LA00012.010', 'Ambra 100', 12, 0], $rows[1]);
        $this->assertSame(['LA00012.050', 'Ambra 500', 6, 0], $rows[2]);
        $this->assertCount(3, $rows); // XX senza scatola in TEA: fuori dal modello
    }

    public function test_filled_template_becomes_order_rows_in_boxes(): void
    {
        $this->fakeBoxes();
        Product::create(['sku' => 'XX999', 'name' => 'Senza scatola', 'price_cents' => 100, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);

        $data = $this->actingAs($this->admin)->post('/ordini-tea/excel', ['file' => $this->excel([
            ['LA00012.050', 'Ambra 500', 6, 2],
            ['LA00012.010', 'Ambra 100', 12, 0],
            ['XX999', 'Senza scatola', null, 1],
            ['NONESISTE', 'Boh', 4, 1],
            ['LA00012.010', 'Ambra 100', 12, 'tre'],
        ])], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertCount(2, $data['rows']);
        $this->assertSame(['LA00012.050', 2, 6], [$data['rows'][0]['sku'], $data['rows'][0]['boxes'], $data['rows'][0]['pcs_per_box']]);
        $this->assertSame(['XX999', 1, 1], [$data['rows'][1]['sku'], $data['rows'][1]['boxes'], $data['rows'][1]['pcs_per_box']]); // senza scatola: a pezzo
        $this->assertSame([3, 13], [$data['boxes'], $data['pieces']]);
        $this->assertCount(2, $data['problems']);
        $this->assertSame(0, TeaOrder::count()); // il file prepara l'ordine, non lo invia
    }

    public function test_import_reports_teaerp_unreachable(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $this->actingAs($this->admin)->post('/ordini-tea/excel', ['file' => $this->excel([['LA00012.050', 'Ambra 500', 6, 2]])], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'pezzi per scatola'));
    }
}
