<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Configurazione > Cassetto contanti: caricamento del fondo cassa. */
class VneRefillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.vne.driver' => 'fake']);
        Cache::flush();
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
    }

    public function test_full_refill_with_simulated_machine(): void
    {
        $this->actingAs($this->admin)->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 300])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('vne_transactions', ['tipo' => 92]);
        $this->assertDatabaseHas('vne_transactions', ['tipo' => 30]);

        $this->travel(5)->seconds();
        $this->postJson('/configurazione/fondo-cassa/stato')->assertOk()->assertJson(['on' => true]);

        $amount = $this->postJson('/configurazione/fondo-cassa/fine')->assertOk()->json('amount');
        $this->assertGreaterThan(0, $amount);
        $this->postJson('/configurazione/fondo-cassa/stato')->assertJson(['on' => false, 'amount' => $amount]);

        $this->postJson('/configurazione/fondo-cassa/conferma')->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('vne_transactions', ['tipo' => 93]);
    }

    public function test_petty_cash_is_optional_and_sent_in_whole_euros(): void
    {
        $this->actingAs($this->admin)->postJson('/configurazione/fondo-cassa/inizio')->assertOk();
        $this->assertDatabaseMissing('vne_transactions', ['tipo' => 92]);

        $this->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 'abc'])->assertStatus(422);
    }

    public function test_tablet_relays_the_commands(): void
    {
        config(['orio.vne.driver' => 'browser', 'orio.vne.host' => '192.168.69.230']);

        $relay = $this->actingAs($this->admin)->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 250])
            ->assertStatus(428)->json('vne_relay');
        $this->assertSame(['tipo' => 92, 'opName' => 'admin', 'pettycash' => 25000], $relay['payload']);

        $first = ['request' => $relay['payload'], 'response' => ['tipo' => 92, 'req_status' => 1]];
        $second = $this->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 250, '_vne_relay' => [$first]])
            ->assertStatus(428)->json('vne_relay.payload');
        $this->assertSame(['tipo' => 30, 'opName' => 'admin', 'acceptAll' => 0], $second);

        $this->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 250, '_vne_relay' => [
            $first, ['request' => $second, 'response' => ['tipo' => 30, 'req_status' => 1]],
        ]])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_machine_error_is_reported(): void
    {
        config(['orio.vne.driver' => 'browser', 'orio.vne.host' => '192.168.69.230']);
        $payload = ['tipo' => 31, 'opName' => 'admin'];

        $this->actingAs($this->admin)->postJson('/configurazione/fondo-cassa/fine', ['_vne_relay' => [
            ['request' => $payload, 'response' => ['tipo' => 31, 'req_status' => 2, 'mess' => 105]],
        ]])->assertStatus(502)->assertJson(['ok' => false]);
    }

    public function test_configuration_page_shows_the_refill_box(): void
    {
        $this->actingAs($this->admin)->get('/configurazione')->assertOk()
            ->assertSee('Carica fondo cassa')->assertSee('Inizia caricamento')->assertSee('Contenuto della cassa')->assertSee('stato-cassa', false)->assertSee('fondo-cassa', false);
    }

    public function test_status_shows_cassettes_detail_and_total(): void
    {
        // Simulata: 405 € + 461 € per il resto, 340 € di banconote e 12,50 € di monete nelle cassette
        $this->actingAs($this->admin)->post('/chiusura/stato-cassa')->assertOk()
            ->assertSeeInOrder(['In macchina', '1.218,50', 'Per il resto', '866,00', 'da svuotare', '352,50'])
            ->assertSeeInOrder(['Cassetta banconote', '340,00', '10,00', '14 pz', '20,00', '5 pz', '50,00', '2 pz'])
            ->assertSeeInOrder(['Cassetta monete', '12,50', '0,50', '5 pz', '1,00', '4 pz', '2,00', '3 pz']);
    }

    public function test_only_admin(): void
    {
        $user = User::create(['name' => 'Cassiera', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);

        $this->actingAs($user)->postJson('/configurazione/fondo-cassa/inizio', ['petty_cash' => 300])->assertForbidden();
    }
}
