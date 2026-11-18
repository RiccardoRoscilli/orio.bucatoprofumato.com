<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordine TEA "in preparazione": resta aperto (stato aperto) e si salva a ogni modifica finche'
 * qualcuno non lo invia. Le righe tengono le scatole; i pezzi si ricalcolano all'invio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tea_order_items', function (Blueprint $table) {
            $table->unsignedInteger('boxes')->nullable()->after('qty');
        });
        Schema::table('tea_orders', function (Blueprint $table) {
            $table->foreignId('updated_by')->nullable()->after('user_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('tea_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('updated_by'));
        Schema::table('tea_order_items', fn (Blueprint $table) => $table->dropColumn('boxes'));
    }
};
