<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Utenti di prova per lo sviluppo locale (non usare in produzione).
     */
    public function run(): void
    {
        User::updateOrCreate(['username' => 'admin'], [
            'name' => 'Amministratore',
            'is_admin' => true,
            'password' => 'orio-admin',
        ]);

        User::updateOrCreate(['username' => 'cassa'], [
            'name' => 'Operatore cassa',
            'is_admin' => false,
            'password' => 'orio-cassa',
        ]);
    }
}
