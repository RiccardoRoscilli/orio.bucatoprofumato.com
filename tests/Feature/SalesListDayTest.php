<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Vendite: la cassa vede solo oggi, l'admin sceglie il giorno. */
class SalesListDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_sees_only_today_and_admin_can_choose_day(): void
    {
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $cassa = User::create(['name' => 'Cassa', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);
        $old = Sale::create(['user_id' => $cassa->id, 'status' => 'scontrinata', 'total_cents' => 1234, 'paid_cents' => 1234]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $yesterday = now()->subDays(3)->toDateString();

        $this->actingAs($cassa)->get('/vendite?day=' . $yesterday)->assertOk()
            ->assertDontSee('type="date"', false)->assertDontSee(euro(1234))->assertSee('Oggi, ' . today()->format('d/m/Y'));

        $this->actingAs($admin)->get('/vendite?day=' . $yesterday)->assertOk()
            ->assertSee('type="date"', false)->assertSee(euro(1234));
    }
}
