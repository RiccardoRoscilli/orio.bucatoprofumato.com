<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivi abilitati a registrare le presenze (il tablet della cassa): una chiave segreta in
 * un cookie del dispositivo, di cui il server tiene solo l'impronta. Ogni turno ricorda da quale
 * dispositivo e indirizzo IP sono arrivate entrata e uscita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('enabled_by')->constrained('users');
            $table->string('enabled_via', 20); // pulsante | codice
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('badge_id')->constrained('attendance_devices');
            $table->string('ip', 45)->nullable()->after('device_id');
            $table->foreignId('end_device_id')->nullable()->after('ended_at')->constrained('attendance_devices');
            $table->string('end_ip', 45)->nullable()->after('end_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropConstrainedForeignId('end_device_id');
            $table->dropColumn(['ip', 'end_ip']);
        });
        Schema::dropIfExists('attendance_devices');
    }
};
