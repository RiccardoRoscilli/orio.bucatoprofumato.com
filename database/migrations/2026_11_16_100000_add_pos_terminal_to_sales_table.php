<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Terminale scelto in cassa (SmartPOS o telefono Tap to Pay): serve anche per il rimborso,
        // che va fatto con la chiave del gruppo di quel terminale
        Schema::table('sales', function (Blueprint $table) {
            $table->string('pos_terminal_id', 30)->nullable()->after('vne_transaction_id');
        });
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('terminal_id', 30)->nullable()->after('sale_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', fn (Blueprint $table) => $table->dropColumn('terminal_id'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('pos_terminal_id'));
    }
};
