<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chiusura fatta dal tablet in automatico alle 23 (senza l'operatrice). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_closures', function (Blueprint $table) {
            $table->boolean('automatic')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closures', fn (Blueprint $table) => $table->dropColumn('automatic'));
    }
};
