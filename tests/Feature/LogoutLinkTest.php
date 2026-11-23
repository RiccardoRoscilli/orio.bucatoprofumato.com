<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le cassiere non hanno «Esci» nel menu: il tablet resta collegato. */
class LogoutLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_sees_logout_but_esci_page_works_for_everyone(): void
    {
        $cassa = User::create(['name' => 'Cassa negozio', 'username' => 'cassa', 'password' => 'x', 'active' => true, 'is_admin' => false]);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);

        $this->actingAs($cassa)->get('/vendite')->assertOk()->assertDontSee('>Esci</button>', false);
        $this->actingAs($admin)->get('/vendite')->assertOk()->assertSee('>Esci</button>', false);

        $this->actingAs($cassa)->get('/esci')->assertOk()->assertSee('Esci e cambia utente');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
