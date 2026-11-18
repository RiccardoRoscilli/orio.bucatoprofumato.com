<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presenze con il badge. I badge sono fissi e numerati (Operatore 1, 2, ...) e si assegnano agli
 * utenti: quando una dipendente va via il badge passa a un'altra. Ogni turno resta sulla persona
 * a cui il badge era assegnato al momento del bip, quindi lo storico non cambia.
 * Le vendite ricordano quale cassiera le ha fatte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->string('code', 20)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('badge_id')->nullable()->constrained();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Uscita non bippata: chiusa da Orio (chiusura giornata, badge tolto), da controllare
            $table->boolean('ended_auto')->default(false);
            $table->foreignId('edited_by')->nullable()->constrained('users');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'ended_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cashier_id')->nullable()->after('user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('cashier_id'));
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('badges');
    }
};
