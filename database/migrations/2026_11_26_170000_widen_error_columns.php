<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaggi d'errore di cassa automatica e POS più lunghi di 255 caratteri (es. le istruzioni
 * per accettare il certificato della VNE): con string() l'insert falliva e il tentativo non
 * veniva registrato.
 */
return new class extends Migration
{
    private const COLUMNS = ['vne_transactions' => 'error', 'pos_transactions' => 'error', 'cash_closures' => 'vne_error'];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->text($column)->nullable()->change());
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->string($column)->nullable()->change());
        }
    }
};
