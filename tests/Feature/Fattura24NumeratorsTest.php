<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Configurazione > Fattura24 > Verifica: elenco dei sezionali con il loro ID. */
class Fattura24NumeratorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_numerators_and_marks_the_one_in_use(): void
    {
        config(['orio.fattura24.api_key' => 'k', 'orio.fattura24.id_numerator' => '7']);
        Http::fake(['*GetNumerator' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <root>
              <doc><id>11</id><des>Fattura elettronica</des></doc>
              <sezionale><id>1</id><code>-{YYYY}-FE</code><anteprima><![CDATA[01-2026-FE]]></anteprima><doc><id>11</id><stato>2</stato></doc></sezionale>
              <sezionale><id>7</id><code>-ORIO-{YYYY}</code><anteprima><![CDATA[01-ORIO-2026]]></anteprima><doc><id>11</id><stato>1</stato></doc></sezionale>
            </root>
            XML)]);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);

        $this->actingAs($admin)->postJson('/configurazione/prova-fattura24')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('numerators.1', ['id' => '7', 'preview' => '01-ORIO-2026', 'selected' => true]);
    }

    public function test_without_key(): void
    {
        config(['orio.fattura24.api_key' => null]);
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'password' => 'x', 'active' => true, 'is_admin' => true]);

        $this->actingAs($admin)->postJson('/configurazione/prova-fattura24')->assertJsonPath('ok', false);
    }
}
