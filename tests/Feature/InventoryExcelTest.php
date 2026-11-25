<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Magazzino > Esporta inventario Excel. */
class InventoryExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_inventory_with_stock_and_value(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $a = Product::create(['sku' => '0123', 'ean' => '8001234567890', 'name' => 'Ammorbidente', 'price_cents' => 1000, 'vat_rate' => 22, 'active' => true, 'sold_in_shop' => true, 'in_stock' => true]);
        $a->forceFill(['stock_qty' => 3])->save();
        $old = Product::create(['sku' => 'OLD', 'name' => 'Fuori vendita', 'price_cents' => 500, 'vat_rate' => 22, 'active' => false, 'sold_in_shop' => false, 'in_stock' => true]);
        $old->forceFill(['stock_qty' => 2])->save();
        Product::create(['sku' => 'NO', 'name' => 'Fuori vendita senza giacenza', 'price_cents' => 500, 'vat_rate' => 22, 'active' => false, 'sold_in_shop' => false, 'in_stock' => true]);

        $response = $this->actingAs($admin)->get('/magazzino/inventario/excel')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'inv') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Giacenza', $sheet->getCell('F1')->getValue());
        $this->assertSame('Ammorbidente', $sheet->getCell('D2')->getValue());
        $this->assertSame('0123', $sheet->getCell('B2')->getValue());
        $this->assertSame('8001234567890', $sheet->getCell('C2')->getValue());
        $this->assertEquals(30, $sheet->getCell('H2')->getCalculatedValue());
        $this->assertSame('Fuori vendita', $sheet->getCell('D3')->getValue());
        $this->assertSame('Totale', $sheet->getCell('D4')->getValue());
        $this->assertEquals(40, $sheet->getCell('H4')->getCalculatedValue());
        @unlink($path);
    }

    public function test_only_admin(): void
    {
        $user = User::create(['name' => 'Cassa', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);

        $this->actingAs($user)->get('/magazzino/inventario/excel')->assertForbidden();
    }
}
