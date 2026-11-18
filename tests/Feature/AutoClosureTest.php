<?php

namespace Tests\Feature;

use App\Models\AttendanceDevice;
use App\Models\CashClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_tablet_closes_the_day_automatically_in_the_evening_window(): void
    {
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake', 'orio.closure.auto_at' => '23:00', 'orio.closure.auto_until' => '23:55']);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $cassa = User::create(['name' => 'Cassa negozio', 'username' => 'cassa', 'password' => 'x', 'active' => true]);

        // Un PC non abilitato: niente timer e niente chiusura automatica
        $this->travelTo(today()->setTime(23, 5));
        $this->actingAs($cassa)->get('/')->assertDontSee('orio-auto-closure', false);
        $this->postJson('/chiusura/automatica')->assertForbidden();

        AttendanceDevice::create(['name' => 'Tablet cassa', 'token_hash' => hash('sha256', str_repeat('t', 64)), 'enabled_by' => $admin->id, 'enabled_via' => 'pulsante']);
        $this->withCredentials()->withCookie(AttendanceDevice::COOKIE, str_repeat('t', 64));
        $this->get('/')->assertSee('orio-auto-closure', false);

        // Prima delle 23 non e' ora
        $this->travelTo(today()->setTime(22, 30));
        $this->postJson('/chiusura/automatica')->assertJson(['due' => false]);

        // Alle 23:05 si': una scheda sola per volta
        $this->travelTo(today()->setTime(23, 5));
        $this->postJson('/chiusura/automatica')->assertJson(['due' => true]);
        $this->postJson('/chiusura/automatica')->assertJson(['due' => false]);

        // Il tablet fa la chiusura (stampante simulata) segnandola automatica, poi l'esito
        $closure = $this->postJson('/chiusura/fiscale', ['automatic' => true])->assertOk()->json('closure');
        $this->postJson("/chiusura/{$closure['id']}/vne")->assertOk();
        $this->postJson('/chiusura/automatica/esito', ['ok' => true, 'message' => 'Chiusura fatta (Z 0001).'])->assertOk();
        $this->assertTrue(CashClosure::find($closure['id'])->automatic);

        // Fatta: non riparte, e la pagina Chiusura lo dice
        $this->postJson('/chiusura/automatica')->assertJson(['due' => false]);
        $this->get('/chiusura')->assertOk()->assertSee('Chiusura automatica alle 23:00')->assertSee('Tablet cassa · Chiusura fatta (Z 0001).')->assertSee('Automatica · Cassa negozio');

        // Il giorno dopo, se manca, riparte (dopo l'ultimo esito il blocco e' tolto)
        $this->travelTo(today()->addDay()->setTime(23, 10));
        $this->postJson('/chiusura/automatica')->assertJson(['due' => true]);
        $this->postJson('/chiusura/automatica/esito', ['ok' => false, 'message' => 'Chiusura fiscale non riuscita: stampante spenta'])->assertOk();
        $this->postJson('/chiusura/automatica')->assertJson(['due' => true, 'vne_only' => null]); // ritenta tutto
        $this->postJson('/chiusura/automatica/esito', ['ok' => false, 'message' => 'x']);

        // Fiscale riuscita ma VNE no: si ritenta solo la cassa automatica
        $second = CashClosure::create(['user_id' => $cassa->id, 'automatic' => true, 'day' => today(), 'fiscal_z_number' => '0002', 'fiscal_closed_at' => now(), 'orio_cash_cents' => 0, 'orio_card_cents' => 0, 'orio_pos_cents' => 0]);
        $this->postJson('/chiusura/automatica')->assertJson(['due' => true, 'vne_only' => $second->id]);
    }
}
