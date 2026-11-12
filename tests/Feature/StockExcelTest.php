<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockExcelTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $admin;
    private Product $a;
    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $this->a = Product::create(['sku' => 'A1', 'ean' => '8000000000017', 'name' => 'Ammorbidente', 'price_cents' => 1000, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $this->b = Product::create(['sku' => 'B1', 'name' => 'Bucato', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        Product::create(['sku' => 'X1', 'name' => 'Escluso', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true, 'sold_in_shop' => false]);
    }

    private function file(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray(array_merge([['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Giacenza attuale', 'Quantità']], $rows));
        $path = tempnam(sys_get_temp_dir(), 'orio') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'magazzino.xlsx', null, null, true);
    }

    public function test_template_lists_only_products_sold_in_shop(): void
    {
        $response = $this->actingAs($this->operator)->get('/magazzino/excel')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'orio') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame('Quantità', $rows[0][6]);
        $this->assertSame(['Ammorbidente', 'Bucato'], array_column(array_slice($rows, 1), 3));
        $this->assertSame('8000000000017', $rows[1][2]);
        $this->assertEquals(0, $rows[1][5]);
    }

    public function test_load_adds_quantities_and_skips_empty_rows(): void
    {
        $this->a->forceFill(['stock_qty' => 2])->save();

        $this->actingAs($this->operator)->post('/magazzino/excel', ['mode' => 'carico', 'note' => 'DDT 9', 'file' => $this->file([
            [$this->a->id, 'A1', '', 'Ammorbidente', '', 2, 6],
            [$this->b->id, 'B1', '', 'Bucato', '', 0, null],
            ['', '', '', 'Sconosciuto', '', 0, 3],
        ])])->assertRedirect('/magazzino')->assertSessionHas('status')->assertSessionHas('error');

        $this->assertSame(8.0, $this->a->fresh()->stock_qty);
        $this->assertSame(0.0, $this->b->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->a->id, 'type' => 'carico', 'note' => 'Carico da Excel DDT 9']);
        $this->assertCount(1, Storage::disk('local')->files('magazzino'));
    }

    public function test_inventory_replaces_quantities_and_is_admin_only(): void
    {
        $this->a->forceFill(['stock_qty' => 5])->save();
        $file = fn () => $this->file([['', 'A1', '', '', '', 5, 3], ['', '', '', '', '', '', '4,5'], ['', 'B1', '', '', '', 0, '0']]);

        $this->actingAs($this->operator)->post('/magazzino/excel', ['mode' => 'inventario', 'file' => $file()])->assertForbidden();
        $this->assertSame(5.0, $this->a->fresh()->stock_qty);

        $this->actingAs($this->admin)->post('/magazzino/excel', ['mode' => 'inventario', 'file' => $file()])
            ->assertRedirect('/magazzino/inventario');

        $this->assertSame(3.0, $this->a->fresh()->stock_qty);
        $this->assertSame(0.0, $this->b->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->a->id, 'type' => 'rettifica', 'qty' => -2]);
    }

    public function test_id_with_different_sku_is_rejected(): void
    {
        $this->actingAs($this->operator)->post('/magazzino/excel', ['mode' => 'carico', 'file' => $this->file([
            [$this->a->id, 'B1', '', '', '', 0, 4],
        ])])->assertSessionHas('error');

        $this->assertSame(0.0, $this->a->fresh()->stock_qty);
        $this->assertSame(0.0, $this->b->fresh()->stock_qty);
    }
}
