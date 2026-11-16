<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.pos.driver' => 'manual', 'orio.pos.terminals' => null, 'orio.pos.terminal_id' => null, 'orio.pos.api_key' => null]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
    }

    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'company' => ['name' => 'LE ESSENZE DI ELDA SRL', 'address' => 'Via Portico 71', 'vat_code' => '04872330164'],
            'fiscal_printer' => ['driver' => 'axon', 'host' => '192.168.1.50', 'departments' => [22 => 1, 10 => 2, 5 => 3, 4 => 4], 'payment_cash' => 1, 'payment_card' => 2],
            'vne' => ['driver' => 'direct', 'host' => '192.168.1.60', 'op_name' => 'orio'],
            'pos' => ['driver' => 'multisafepay'],
            'terminals' => [
                ['name' => 'SmartPOS', 'id' => '000000MB', 'api_key' => 'pos-key-1234', 'active' => '1', 'original' => ''],
                ['name' => 'Telefono Tap to Pay', 'id' => '000001AB', 'api_key' => 'tap-key-5678', 'active' => '1', 'original' => ''],
            ],
            'fattura24' => ['api_key' => 'f24-secret-9999', 'id_numerator' => ''],
            'closure' => ['reminder_at' => '19:00', 'alert_at' => '21:00', 'alert_emails' => 'negozio@example.com'],
            'tea' => [
                'url' => 'https://erp.example.test', 'user' => 'orio-api', 'password' => 'tea-pass-0000',
                'shipping' => ['company' => 'Negozio Orio Center', 'street' => 'Via Portico 71 - negozio 12', 'city' => 'Orio al Serio', 'postcode' => '24050', 'region' => 'bg'],
            ],
        ], $overrides);
    }

    public function test_only_admin_can_open_configuration(): void
    {
        $operator = User::create(['name' => 'Op', 'username' => 'op', 'password' => 'x', 'active' => true]);
        $this->actingAs($operator)->get('/configurazione')->assertForbidden();
        $this->actingAs($this->admin)->get('/configurazione')->assertOk()->assertSee('Terminali carta');
    }

    public function test_saved_values_override_env_and_secrets_are_encrypted(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form())->assertRedirect('/configurazione')->assertSessionHasNoErrors();

        // Valgono subito e anche alle richieste successive (rilette dal database)
        config(['orio.fiscal_printer.host' => 'dal-env', 'orio.tea.password' => 'dal-env']);
        Cache::flush();
        Settings::apply();
        $this->assertSame('192.168.1.50', config('orio.fiscal_printer.host'));
        $this->assertSame([22 => 1, 10 => 2, 5 => 3, 4 => 4], config('orio.fiscal_printer.departments'));
        $this->assertSame('192.168.1.60', config('orio.vne.host'));
        $this->assertSame('tea-pass-0000', config('orio.tea.password'));
        $this->assertSame('BG', config('orio.tea.shipping.region'));
        $this->assertSame(['negozio@example.com'], config('orio.closure.alert_emails'));

        // Nel database e nella pagina le chiavi non compaiono in chiaro
        $this->assertStringNotContainsString('tea-pass-0000', Setting::find('tea.password')->value);
        $this->assertStringNotContainsString('tap-key-5678', Setting::find('pos.terminals')->value);
        $this->get('/configurazione')->assertOk()
            ->assertDontSee('tea-pass-0000')->assertDontSee('tap-key-5678')->assertDontSee('f24-secret-9999')
            ->assertSee('Salvata (••••0000)')->assertSee('5678', false); // ultime cifre della chiave del telefono, per riconoscerla
    }

    public function test_blank_keys_keep_the_saved_ones(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form())->assertSessionHasNoErrors();

        // Secondo salvataggio senza riscrivere le chiavi, con il telefono rinominato e disattivato
        $this->put('/configurazione', $this->form([
            'terminals' => [
                ['name' => 'SmartPOS', 'id' => '000000MB', 'api_key' => '', 'active' => '1', 'original' => '000000MB'],
                ['name' => 'Telefono', 'id' => '000001AB', 'api_key' => '', 'active' => '0', 'original' => '000001AB'],
            ],
            'fattura24' => ['api_key' => ''],
            'tea' => ['password' => ''],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('f24-secret-9999', config('orio.fattura24.api_key'));
        $this->assertSame('tea-pass-0000', config('orio.tea.password'));
        $this->assertSame(
            [['id' => '000000MB', 'name' => 'SmartPOS', 'api_key' => 'pos-key-1234']],
            \App\Services\Pos\CloudPos::terminals(),
        );
    }

    public function test_cassa_uses_terminals_from_configuration(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form())->assertSessionHasNoErrors();
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['order_id' => 'x']])]);

        $this->get('/')->assertOk()->assertSee('Telefono Tap to Pay')->assertDontSee('tap-key-5678');

        $product = \App\Models\Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);
        $saleId = $this->postJson('/vendite', ['document_type' => 'scontrino', 'items' => [['product_id' => $product->id, 'qty' => 1]]])->json('sale.id');
        $this->postJson("/vendite/{$saleId}/pagamento", ['method' => 'pos', 'terminal' => '000001AB'])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api_key=tap-key-5678') && $r['gateway_info']['terminal_id'] === '000001AB');
    }

    public function test_invalid_addresses_are_rejected(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form([
            'fiscal_printer' => ['host' => 'http://192.168.1.50/'],
            'closure' => ['alert_emails' => 'negozio@example.com, sbagliata'],
            'terminals' => [1 => ['id' => '000000MB']],
        ]))->assertSessionHasErrors(['fiscal_printer.host', 'closure.alert_emails', 'terminals.1.id']);

        $this->assertSame(0, Setting::count());
    }

    public function test_terminal_connection_test_checks_the_group_key(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form())->assertSessionHasNoErrors();

        Http::fake(fn (Request $r) => str_contains($r->url(), 'api_key=tap-key-5678')
            ? Http::response(['success' => false, 'error_code' => 1006, 'error_info' => 'Invalid transaction ID'], 404)
            : Http::response(['success' => false, 'error_code' => 1032, 'error_info' => 'Invalid API key'], 403));

        $this->postJson('/configurazione/prova-pos', ['terminal' => '000001AB'])->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/configurazione/prova-pos', ['terminal' => '000000MB'])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/configurazione/prova-pos', ['terminal' => 'NONESISTE'])->assertStatus(422);
    }

    public function test_tea_connection_test(): void
    {
        $this->actingAs($this->admin)->put('/configurazione', $this->form())->assertSessionHasNoErrors();

        $status = 404;
        Http::fake(function () use (&$status) {
            return $status === 404
                ? Http::response(['ok' => false, 'error' => ['message' => 'Ordine non trovato']], 404)
                : Http::response(['message' => 'Unauthenticated'], 401);
        });
        $this->postJson('/configurazione/prova-tea')->assertOk()->assertJson(['ok' => true]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://erp.example.test/api/orio/orders/ORIO-PROVA-COLLEGAMENTO');

        $status = 401;
        $this->postJson('/configurazione/prova-tea')->assertOk()->assertJson(['ok' => false]);
    }
}
