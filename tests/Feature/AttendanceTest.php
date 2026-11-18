<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceDevice;
use App\Models\Badge;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Presenze col badge e cassiera della vendita. */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $tablet;
    private User $admin;
    private User $maria;
    private User $giulia;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake', 'orio.pos.driver' => 'manual']);
        $this->tablet = User::create(['name' => 'Cassa negozio', 'username' => 'cassa', 'password' => 'x', 'active' => true]);
        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $this->maria = User::create(['name' => 'Maria', 'username' => 'maria', 'password' => 'x', 'active' => true]);
        $this->giulia = User::create(['name' => 'Giulia', 'username' => 'giulia', 'password' => 'x', 'active' => true]);
        $this->product = Product::create(['sku' => 'A1', 'name' => 'Ammorbidente', 'price_cents' => 500, 'vat_rate' => 22, 'active' => true, 'in_stock' => true]);

        // Il tablet della cassa e' abilitato alle presenze
        AttendanceDevice::create(['name' => 'Tablet cassa', 'token_hash' => hash('sha256', str_repeat('t', 64)), 'enabled_by' => $this->admin->id, 'enabled_via' => 'pulsante']);
        $this->withCredentials()->withCookie(AttendanceDevice::COOKIE, str_repeat('t', 64)); // anche nelle chiamate JSON
    }

    /** Nuovo badge assegnato alla persona: ritorna il codice del QR. */
    private function giveBadge(User $user): string
    {
        $badge = Badge::createNext(1)->first();
        $badge->assignTo($user);

        return $badge->code;
    }

    private function sell(?int $cashierId = null)
    {
        return $this->actingAs($this->tablet)->postJson('/vendite', [
            'document_type' => 'scontrino',
            'cashier_id' => $cashierId,
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
        ]);
    }

    public function test_numbered_badges_are_created_printed_and_reassigned(): void
    {
        $this->actingAs($this->admin)->post('/badge', ['count' => 3])->assertRedirect('/badge');
        $this->assertSame([1, 2, 3], Badge::orderBy('number')->pluck('number')->all());
        $this->assertMatchesRegularExpression('/^ORIO[A-Z0-9]{8}$/', Badge::first()->code);
        $this->get('/badge/stampa')->assertOk()->assertSee('Operatore')->assertSee(Badge::find(3)->code);

        // Badge 2 a Maria: entra e lavora
        $badge = Badge::where('number', 2)->first();
        $this->put("/badge/{$badge->id}", ['user_id' => $this->maria->id])->assertRedirect();
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $badge->code])->assertJson(['action' => 'started', 'user' => ['name' => 'Maria']]);

        // Maria va via: il badge passa a Giulia. Il turno aperto di Maria si chiude, da controllare
        $this->actingAs($this->admin)->put("/badge/{$badge->id}", ['user_id' => $this->giulia->id])->assertSessionHas('status', 'Operatore 2 assegnato a Giulia.');
        $maria = Attendance::where('user_id', $this->maria->id)->first();
        $this->assertNotNull($maria->ended_at);
        $this->assertTrue($maria->ended_auto);

        // Lo stesso badge ora registra Giulia; lo storico di Maria resta suo
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $badge->code])->assertJson(['action' => 'started', 'user' => ['name' => 'Giulia']]);
        $this->assertSame([$this->maria->id, $this->giulia->id], Attendance::orderBy('id')->pluck('user_id')->all());
        $this->assertSame([$badge->id, $badge->id], Attendance::orderBy('id')->pluck('badge_id')->all());

        // Giulia riceve un altro badge: il 2 si libera
        $this->actingAs($this->admin)->put('/badge/' . Badge::where('number', 3)->value('id'), ['user_id' => $this->giulia->id])
            ->assertSessionHas('status', 'Operatore 3 assegnato a Giulia; il suo Operatore 2 ora è libero.');
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $badge->code])->assertStatus(422)
            ->assertJsonPath('message', 'Operatore 2 non è assegnato a nessuno: si assegna da Gestione > Badge.');

        // Badge perso: nuovo codice, il vecchio non funziona piu'
        $old = $badge->code;
        $this->actingAs($this->admin)->post("/badge/{$badge->id}/sostituisci")->assertRedirect();
        $this->assertNotSame($old, $badge->fresh()->code);
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $old])->assertNotFound();

        // Gli operatori non gestiscono i badge
        $this->get('/badge')->assertForbidden();
    }

    public function test_badge_starts_and_ends_the_shift(): void
    {
        $code = $this->giveBadge($this->maria);
        $this->travelTo(today()->setTime(9, 2));

        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => strtolower($code)])->assertOk()
            ->assertJson(['action' => 'started', 'present' => [['name' => 'Maria', 'since' => '09:02']]]);

        // Secondo bip: Orio chiede conferma prima di chiudere
        $this->travelTo(today()->setTime(13, 30));
        $this->postJson('/presenze/badge', ['code' => $code])->assertJson(['action' => 'ask_end']);
        $this->assertSame(1, Attendance::open()->count());

        $this->postJson('/presenze/badge', ['code' => $code, 'end' => true])->assertJson(['action' => 'ended', 'present' => []])
            ->assertJsonPath('message', 'Arrivederci Maria: uscita alle 13:30, turno di 4 h 28.');
        $this->assertSame(268, Attendance::first()->minutes());
    }

    public function test_sales_without_badges_work_as_before(): void
    {
        $this->sell()->assertOk();
        $this->assertNull(Sale::first()->cashier_id);
    }

    public function test_one_present_cashier_gets_the_sale(): void
    {
        $code = $this->giveBadge($this->maria);
        $this->giveBadge($this->giulia);

        // Badge in uso ma nessuna presente: la cassa chiede il bip
        $this->sell()->assertStatus(409)->assertJson(['cashier_required' => true, 'present' => []]);

        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $code])->assertJson(['action' => 'started']);
        $this->sell()->assertOk();
        $this->assertSame($this->maria->id, Sale::first()->cashier_id);
    }

    public function test_with_two_present_each_sale_asks_who(): void
    {
        $maria = $this->giveBadge($this->maria);
        $giulia = $this->giveBadge($this->giulia);
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $maria]);
        $this->postJson('/presenze/badge', ['code' => $giulia]);

        $this->sell()->assertStatus(409)->assertJsonCount(2, 'present');

        // Bip nella finestra "chi fa cassa?": sceglie Giulia senza chiuderle il turno
        $this->postJson('/presenze/badge', ['code' => $giulia, 'select' => true])->assertJson(['action' => 'selected', 'user' => ['id' => $this->giulia->id]]);
        $this->assertSame(2, Attendance::open()->count());

        $this->sell($this->giulia->id)->assertOk();
        $this->assertSame($this->giulia->id, Sale::first()->cashier_id);

        // Una cassiera non presente non si puo' indicare
        $this->postJson('/presenze/badge', ['code' => $maria, 'end' => true]);
        $this->postJson('/vendite', ['document_type' => 'scontrino', 'cashier_id' => $this->maria->id, 'items' => [['product_id' => $this->product->id, 'qty' => 1]]])->assertOk();
        $this->assertSame($this->giulia->id, Sale::latest('id')->first()->cashier_id); // resta solo Giulia

        $this->get('/vendite/' . Sale::first()->id)->assertSee('Cassiera')->assertSee('Giulia');
    }

    public function test_forgotten_exit_is_closed_and_flagged(): void
    {
        $code = $this->giveBadge($this->maria);
        $this->travelTo(today()->subDay()->setTime(9, 0));
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $code]);

        // Il giorno dopo il bip apre un turno nuovo; quello di ieri si chiude a fine giornata
        $this->travelTo(today()->addDay()->setTime(9, 5));
        $this->postJson('/presenze/badge', ['code' => $code])->assertJson(['action' => 'started']);
        $old = Attendance::orderBy('id')->first();
        $this->assertTrue($old->ended_auto);
        $this->assertSame('23:59', $old->ended_at->format('H:i'));

        // La chiusura cassa chiude chi e' ancora dentro
        Attendance::closeOpen();
        $this->assertSame(0, Attendance::open()->count());

        $this->actingAs($this->admin)->get('/presenze')->assertOk()->assertSee('da controllare')->assertSee('Maria');
    }

    public function test_admin_corrects_a_shift_and_exports_excel(): void
    {
        $shift = Attendance::create(['user_id' => $this->maria->id, 'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(23, 59), 'ended_auto' => true]);

        $this->actingAs($this->admin)->put("/presenze/{$shift->id}", [
            'started_at' => now()->format('Y-m-d') . 'T09:00', 'ended_at' => now()->format('Y-m-d') . 'T17:30', 'note' => 'Uscita dimenticata',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $shift->refresh();
        $this->assertFalse($shift->ended_auto);
        $this->assertSame($this->admin->id, $shift->edited_by);
        $this->assertSame(510, $shift->minutes());

        $this->put("/presenze/{$shift->id}", ['started_at' => now()->format('Y-m-d') . 'T18:00', 'ended_at' => now()->format('Y-m-d') . 'T17:00'])
            ->assertSessionHasErrors('ended_at');

        $this->post('/presenze', ['user_id' => $this->giulia->id, 'started_at' => now()->format('Y-m-d') . 'T14:00', 'ended_at' => now()->format('Y-m-d') . 'T19:00'])->assertRedirect();
        $this->assertSame(2, Attendance::count());

        $this->get('/presenze/excel')->assertOk()->assertDownload('presenze-' . now()->format('Y-m') . '.xlsx');
        $this->actingAs($this->tablet)->get('/presenze')->assertForbidden();
    }

    public function test_presences_only_from_enabled_devices(): void
    {
        $code = $this->giveBadge($this->maria);

        // Da casa, anche con il login fatto: niente presenza
        $this->defaultCookies = [];
        $this->actingAs($this->maria)
            ->postJson('/presenze/badge', ['code' => $code])->assertForbidden()->assertJson(['device_required' => true]);
        $this->assertSame(0, Attendance::count());

        // Il tablet abilitato registra, con dispositivo e IP
        $this->withCookie(AttendanceDevice::COOKIE, str_repeat('t', 64))->actingAs($this->tablet)
            ->postJson('/presenze/badge', ['code' => $code])->assertOk()->assertCookie(AttendanceDevice::COOKIE);
        $shift = Attendance::first();
        $this->assertSame('Tablet cassa', $shift->device->name);
        $this->assertSame('127.0.0.1', $shift->ip);
        $this->assertNotNull(AttendanceDevice::first()->last_used_at);

        // Revocato: smette di funzionare
        $this->actingAs($this->admin)->delete('/badge/dispositivi/' . AttendanceDevice::first()->id)->assertRedirect();
        $this->actingAs($this->tablet)->postJson('/presenze/badge', ['code' => $code])->assertForbidden();
    }

    public function test_device_is_enabled_with_the_button_or_a_code(): void
    {
        $this->defaultCookies = [];

        // Admin davanti al tablet
        $response = $this->actingAs($this->admin)->post('/badge/dispositivi', ['name' => 'Tablet cassa 2'])->assertRedirect('/badge');
        $token = $response->getCookie(AttendanceDevice::COOKIE)->getValue();
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), AttendanceDevice::where('name', 'Tablet cassa 2')->value('token_hash'));

        // Admin da un'altra parte: codice di 6 cifre, una volta sola e solo per 10 minuti
        $code = $this->post('/badge/dispositivi/codice')->assertRedirect()->assertSessionHas('device_code')->getSession()->get('device_code')['code'];
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->get('/badge')->assertSee(substr($code, 0, 3));

        $this->actingAs($this->tablet)->postJson('/presenze/abilita', ['code' => '000000' === $code ? '111111' : '000000'])->assertStatus(422);
        $this->postJson('/presenze/abilita', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)])->assertOk()->assertCookie(AttendanceDevice::COOKIE);
        $this->assertSame('codice', AttendanceDevice::latest('id')->first()->enabled_via);
        $this->assertSame($this->admin->id, AttendanceDevice::latest('id')->first()->enabled_by);
        $this->postJson('/presenze/abilita', ['code' => $code])->assertStatus(422); // gia' usato

        $expired = $this->actingAs($this->admin)->post('/badge/dispositivi/codice')->getSession()->get('device_code')['code'];
        $this->travel(11)->minutes();
        $this->actingAs($this->tablet)->postJson('/presenze/abilita', ['code' => $expired])->assertStatus(422);

        // Solo gli admin generano codici o abilitano dispositivi
        $this->post('/badge/dispositivi/codice')->assertForbidden();
        $this->post('/badge/dispositivi', ['name' => 'Telefono'])->assertForbidden();
    }
}
