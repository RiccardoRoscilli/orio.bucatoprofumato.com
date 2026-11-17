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

    public function test_filled_template_becomes_order_rows_in_pieces(): void
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

        $this->assertCount(1, $data['rows']);
        $this->assertSame(['LA00012.050', 12, 6], [$data['rows'][0]['sku'], $data['rows'][0]['qty'], $data['rows'][0]['pcs_per_box']]);
        $this->assertSame([2, 12], [$data['boxes'], $data['pieces']]);
        $this->assertCount(3, $data['problems']);
        $this->assertSame(0, TeaOrder::count()); // il file prepara l'ordine, non lo invia
    }

    public function test_import_reports_teaerp_unreachable(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $this->actingAs($this->admin)->post('/ordini-tea/excel', ['file' => $this->excel([['LA00012.050', 'Ambra 500', 6, 2]])], ['Accept' => 'application/json'])
            ->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'pezzi per scatola'));
    }
}
