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
        $book->getActiveSheet()->fromArray(array_merge([['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Quantità']], $rows));
        $path = tempnam(sys_get_temp_dir(), 'orio') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'magazzino.xlsx', null, null, true);
    }

    public function test_template_has_one_quantity_column_and_only_products_sold_in_shop(): void
    {
        $this->a->forceFill(['stock_qty' => 7])->save();
        $response = $this->actingAs($this->operator)->get('/magazzino/excel')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'orio') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(['ID Orio', 'SKU', 'EAN', 'Prodotto', 'Formato', 'Quantità'], array_slice($rows[0], 0, 6));
        $this->assertNull($rows[0][6] ?? null);
        $this->assertSame(['Ammorbidente', 'Bucato'], array_column(array_slice($rows, 1), 3));
        $this->assertSame('8000000000017', $rows[1][2]);
        $this->assertEquals(0, $rows[1][5]);
    }

    public function test_file_replaces_stock_empty_means_zero_and_missing_products_stay(): void
    {
        $this->a->forceFill(['stock_qty' => 5])->save();
        $this->b->forceFill(['stock_qty' => 4])->save();
        $other = Product::where('sku', 'X1')->first();
        $other->forceFill(['stock_qty' => 9])->save();

        $this->actingAs($this->operator)->post('/magazzino/excel', ['note' => 'novembre', 'file' => $this->file([
            [$this->a->id, 'A1', '', 'Ammorbidente', '', '2,5'],
            [$this->b->id, 'B1', '', 'Bucato', '', null],
            ['', '', '', '', '', ''],
            ['', 'NONESISTE', '', 'Sconosciuto', '', 3],
        ])])->assertRedirect('/magazzino')->assertSessionHas('status')->assertSessionHas('error');

        $this->assertSame(2.5, $this->a->fresh()->stock_qty);
        $this->assertSame(0.0, $this->b->fresh()->stock_qty);
        $this->assertSame(9.0, $other->fresh()->stock_qty);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->a->id, 'type' => 'rettifica', 'qty' => -2.5, 'note' => 'Giacenze da Excel novembre']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $this->b->id, 'type' => 'rettifica', 'qty' => -4]);
        $this->assertCount(1, Storage::disk('local')->files('magazzino'));
    }

    public function test_same_quantity_leaves_no_movement(): void
    {
        $this->a->forceFill(['stock_qty' => 3])->save();

        $this->actingAs($this->operator)->post('/magazzino/excel', ['mode' => 'inventario', 'file' => $this->file([
            ['', 'A1', '', '', '', 3],
        ])])->assertRedirect('/magazzino/inventario');

        $this->assertSame(3.0, $this->a->fresh()->stock_qty);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_id_with_different_sku_is_rejected(): void
    {
        $this->actingAs($this->operator)->post('/magazzino/excel', ['file' => $this->file([
            [$this->a->id, 'B1', '', '', '', 4],
        ])])->assertSessionHas('error');

        $this->assertSame(0.0, $this->a->fresh()->stock_qty);
        $this->assertSame(0.0, $this->b->fresh()->stock_qty);
    }
}
