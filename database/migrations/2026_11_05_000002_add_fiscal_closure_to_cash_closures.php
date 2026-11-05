<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_closures', function (Blueprint $table) {
            // Inizio del periodo coperto: la chiusura precedente (o la prima vendita)
            $table->timestamp('period_from')->nullable()->after('day');
            // Chiusura fiscale della stampante RT (report Z)
            $table->string('fiscal_z_number')->nullable()->after('period_from');
            $table->timestamp('fiscal_closed_at')->nullable()->after('fiscal_z_number');
            // Chiusura della cassa automatica VNE (tipo 60)
            $table->timestamp('vne_closed_at')->nullable()->after('vne_data');
            $table->string('vne_error')->nullable()->after('vne_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closures', function (Blueprint $table) {
            $table->dropColumn(['period_from', 'fiscal_z_number', 'fiscal_closed_at', 'vne_closed_at', 'vne_error']);
        });
    }
};
