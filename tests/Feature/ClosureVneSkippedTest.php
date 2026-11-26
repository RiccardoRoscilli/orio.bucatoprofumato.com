<?php

namespace Tests\Feature;

use App\Models\CashClosure;
use App\Models\User;
use App\Models\VneTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosureVneSkippedTest extends TestCase
{
    use RefreshDatabase;

    public function test_closure_marked_resolved_is_no_longer_pending(): void
    {
        config(['orio.vne.driver' => 'fake', 'orio.fiscal_printer.driver' => 'fake']);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);
        $closure = CashClosure::create(['user_id' => $admin->id, 'day' => today(), 'fiscal_z_number' => '0003', 'fiscal_closed_at' => now(),
            'vne_error' => 'Cassa VNE non raggiungibile', 'orio_cash_cents' => 1000, 'orio_card_cents' => 0, 'orio_pos_cents' => 0]);

        $this->actingAs($admin)->get('/chiusura')->assertSee('la cassa automatica non è stata chiusa');

        $closure->update(['vne_closed_at' => now(), 'vne_data' => ['skipped' => true]]);

        $this->actingAs($admin)->get('/chiusura')->assertOk()
            ->assertDontSee('la cassa automatica non è stata chiusa')
            ->assertSee('non chiusa');
    }

    public function test_long_vne_error_is_saved(): void
    {
        $error = str_repeat('il tablet non raggiunge la cassa automatica ', 10);
        VneTransaction::create(['tipo' => 1, 'request' => ['importo' => 200], 'error' => $error]);

        $this->assertSame($error, VneTransaction::first()->error);
    }
}
