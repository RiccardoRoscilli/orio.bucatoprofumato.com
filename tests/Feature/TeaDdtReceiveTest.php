<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TeaDdtItem;
use App\Models\TeaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeaDdtReceiveTest extends TestCase
{
    use RefreshDatabase;

    private function teaOrder(array $ddts): array
    {
        return ['ok' => true, 'order' => ['increment_id' => 'orio-000009', 'erp_status' => 'Complete', 'ddts' => $ddts]];
    }

    public function test_ddt_rows_are_checked_and_loaded_into_stock(): void
    {
        config(['orio.tea.url' => 'https://teaerp.example', 'orio.tea.user' => 'orio-api@teaerp.local', 'orio.tea.password' => 'secret']);
        $admin = User::create(['name' => 'Ad', 'username' => 'ad', 'password' => 'x', 'is_admin' => true, 'active' => true]);
        $ambra = Product::create(['sku' => 'LA00012.050', 'name' => 'Ambra 500', 'price_cents' => 1500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $ambra->forceFill(['stock_qty' => 2])->save();
        $lavanda = Product::create(['sku' => 'LA00087.050', 'name' => 'Lavanda 500', 'price_cents' => 1500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $order = TeaOrder::create(['status' => 'inviato', 'reference' => 'ORIO-1', 'user_id' => $admin->id, 'tea_increment_id' => 'orio-000009']);

        $first = ['ddt_number' => 'VE-00012/2026', 'issued_at' => '2026-11-20', 'is_fully_shipped' => false, 'items' => [
            ['sku' => 'LA00012.050', 'name' => 'Ambra 500', 'qty_shipped' => 24],
            ['sku' => 'LA00087.050', 'name' => 'Lavanda 500', 'qty_shipped' => 6],
            ['sku' => 'BOH', 'name' => 'Non in Orio', 'qty_shipped' => 1],
        ]];
        $second = ['ddt_number' => 'VE-00015/2026', 'issued_at' => '2026-11-22', 'is_fully_shipped' => true, 'items' => [
            ['sku' => 'LA00087.050', 'name' => 'Lavanda 500', 'qty_shipped' => 6],
        ]];
        Http::fake(['teaerp.example/api/emmycasa/product-boxes' => Http::response(['ok' => true, 'products' => [
            ['sku' => 'LA00012.050', 'pezzi_per_box' => 12], ['sku' => 'LA00087.050', 'pezzi_per_box' => 6],
        ]])]);
        Http::fakeSequence('teaerp.example/api/orio/*')
            ->push($this->teaOrder([]))           // apertura: niente DDT ancora
            ->push($this->teaOrder([$first]))     // aggiorna: primo DDT
            ->push($this->teaOrder([$first, $second])); // aggiorna: spedizione parziale successiva

        $this->actingAs($admin)->get("/ordini-tea/{$order->id}")->assertOk()->assertSee('TEA non ha ancora emesso il DDT');
        $this->postJson("/ordini-tea/{$order->id}/stato")->assertJsonPath('message', 'Stato aggiornato: 1 DDT nuovo da spuntare.');
        $this->get("/ordini-tea/{$order->id}")->assertOk()->assertSee('DDT VE-00012/2026')->assertSee('{ left: 2, confirmed: false }', false)->assertDontSee('Spunta tutto');

        // Ambra: 2 scatole da 12 spedite, ne arriva 1; poi si trova l'altra. La spunta non tocca il magazzino
        $row = TeaDdtItem::where('sku', 'LA00012.050')->first();
        $this->assertSame([12, 2.0], [$row->pcs_per_box, $row->boxes($row->qty_shipped)]);
        $this->postJson("/ordini-tea/ddt-righe/{$row->id}", ['boxes' => '0.5'])->assertStatus(422); // niente mezze scatole
        $this->postJson("/ordini-tea/ddt-righe/{$row->id}", ['boxes' => 1])->assertOk()->assertJsonPath('item.qty_received', 12)->assertJsonPath('item.boxes_received', 1);
        $this->postJson("/ordini-tea/ddt-righe/{$row->id}", ['boxes' => 2])->assertOk();
        $this->assertSame(2.0, $ambra->fresh()->stock_qty);
        $this->assertSame(0, $ambra->stockMovements()->count());

        // La riga senza prodotto non si carica
        $this->postJson('/ordini-tea/ddt-righe/' . TeaDdtItem::where('sku', 'BOH')->value('id'), ['boxes' => 1])->assertStatus(422);

        // Lavanda: 1 scatola da 6
        $this->postJson('/ordini-tea/ddt-righe/' . TeaDdtItem::where('sku', 'LA00087.050')->value('id'), ['boxes' => 1])->assertOk();
        $this->assertSame(0.0, $lavanda->fresh()->stock_qty);

        // Stato dell'arrivo nello storico: tutto spuntato (la riga fuori Orio non conta), da confermare; manca merce
        $arrival = fn () => TeaOrder::with('items', 'ddts.items')->find($order->id)->arrival();
        $order->items()->create(['product_id' => $lavanda->id, 'sku' => 'LA00087.050', 'name' => 'Lavanda 500', 'qty' => 12]);
        $this->assertSame(['spuntato', 'Spuntato tutto, carico da confermare · manca merce'], [$arrival()['key'], $arrival()['label']]); // ordinati 12, spediti 6
        $order->items()->update(['qty' => 6]);

        // Conferma del carico: solo ora la merce entra in magazzino, poi niente correzioni
        $ddtId = $row->tea_ddt_id;
        $this->postJson("/ordini-tea/ddt/{$ddtId}/conferma")->assertOk()->assertJsonPath('message', 'Carico confermato: 2 prodotti caricati in magazzino.');
        $this->assertSame([26.0, 6.0], [$ambra->fresh()->stock_qty, $lavanda->fresh()->stock_qty]);
        $this->assertSame(['Arrivo DDT VE-00012/2026 (ordine ORIO-1)'], $ambra->stockMovements()->pluck('note')->all());
        $this->postJson("/ordini-tea/ddt/{$ddtId}/conferma")->assertStatus(422);
        $this->postJson("/ordini-tea/ddt-righe/{$row->id}", ['boxes' => 1])->assertStatus(422)->assertJsonPath('message', 'Carico già confermato: non si può più modificare.');
        $this->assertSame(['confermato', 'Completo'], [$arrival()['key'], $arrival()['label']]);

        // Secondo DDT: si aggiunge, il primo resta con le sue spunte
        $this->postJson("/ordini-tea/{$order->id}/stato")->assertJsonPath('message', 'Stato aggiornato: 1 DDT nuovo da spuntare.');
        $this->assertSame(2, $order->ddts()->count());
        $this->assertSame(24.0, $row->fresh()->qty_received);
        $this->assertSame('Spuntato in parte', $arrival()['label']); // primo DDT spuntato, secondo no

        // Anche l'operatrice spunta la merce (sul secondo DDT, il primo e' confermato)
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $second = TeaDdtItem::whereHas('ddt', fn ($q) => $q->where('ddt_number', 'VE-00015/2026'))->first();
        $this->postJson("/ordini-tea/ddt/{$second->tea_ddt_id}/conferma")->assertStatus(422)->assertJsonPath('message', 'Manca ancora 1 riga da spuntare.');
        $this->actingAs($operator)->postJson("/ordini-tea/ddt-righe/{$second->id}", ['boxes' => 1])->assertOk()->assertJsonPath('item.receiver', 'Op');
        $this->assertSame('Spuntato tutto, carico da confermare', $arrival()['label']);
        $this->postJson("/ordini-tea/ddt/{$second->tea_ddt_id}/conferma")->assertOk();
        $this->assertSame(12.0, $lavanda->fresh()->stock_qty);
    }
}
