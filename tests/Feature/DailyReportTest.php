<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Rapporto giornaliero per il telefono (PWA), solo admin. */
class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, bool $admin = false): User
    {
        return User::create(['name' => $name, 'username' => strtolower($name), 'password' => 'x', 'active' => true, 'is_admin' => $admin]);
    }

    private function sale(User $cashier, int $cents, string $method, array $extra = []): Sale
    {
        return Sale::create(array_merge([
            'user_id' => $cashier->id, 'cashier_id' => $cashier->id, 'status' => 'scontrinata', 'payment_method' => $method,
            'total_cents' => $cents, 'paid_cents' => $cents, 'paid_at' => now(),
        ], $extra));
    }

    public function test_admin_sees_totals_by_type_and_operator(): void
    {
        $admin = $this->user('Titolare', true);
        $anna = $this->user('Anna');
        $bea = $this->user('Bea');
        $this->sale($anna, 1000, 'contanti');
        $this->sale($anna, 2500, 'pos');
        $this->sale($bea, 700, 'contanti', ['status' => 'fatturata']);
        $this->sale($bea, 300, 'contanti', ['paid_at' => now()->subDay()]);
        $this->sale($bea, 900, 'contanti')->forceFill(['receipt_voided_at' => now()])->save();

        $this->actingAs($admin)->get('/rapporto')->assertOk()
            ->assertSeeInOrder(['Incassato', euro(4200), '3 vendite', '2 scontrini', '1 fatture'])
            ->assertSeeInOrder(['Contanti (cassa)', euro(1700), 'Carta (banca)', euro(2500)])
            ->assertSeeInOrder(['Anna', '2 vendite', euro(3500), 'Bea', '1 vendita', euro(700)])
            ->assertSee('1 vendita annullata')
            ->assertSee('rapporto/manifest.webmanifest');

        $this->actingAs($admin)->get('/rapporto?giorno=' . now()->subDay()->toDateString())->assertOk()
            ->assertSee('Ieri')->assertSee(euro(300));
    }

    public function test_only_admin_can_open_report_and_install_it(): void
    {
        $cassa = $this->user('Cassa');

        $this->actingAs($cassa)->get('/rapporto')->assertForbidden();
        $this->actingAs($cassa)->get('/rapporto/manifest.webmanifest')->assertForbidden();
        $this->actingAs($cassa)->get('/rapporto/icona-180.png')->assertForbidden();
        auth()->logout();
        $this->get('/rapporto/manifest.webmanifest')->assertRedirect('/login');
    }

    public function test_manifest_for_admin(): void
    {
        $this->actingAs($this->user('Titolare', true))->get('/rapporto/manifest.webmanifest')->assertOk()
            ->assertJsonPath('start_url', '/rapporto')->assertJsonPath('display', 'standalone');
    }
}
