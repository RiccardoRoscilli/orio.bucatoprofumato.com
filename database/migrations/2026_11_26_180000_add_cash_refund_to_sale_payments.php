<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Rimborso dei contanti dalla cassa automatica (VNE tipo 65/66) quando si annulla una vendita. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->timestamp('refund_started_at')->nullable()->after('paid_at');
            $table->unsignedInteger('refunded_cents')->nullable()->after('refunded_at');
        });

        // Vendite gia' annullate: i contanti sono stati restituiti a mano, la cassa non deve ridarli
        DB::table('sale_payments')
            ->whereIn('sale_id', DB::table('sales')->where('status', 'annullata')->select('id'))
            ->whereNotIn('method', ['pos', 'carta'])
            ->whereNull('refunded_at')
            ->update(['refunded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('sale_payments', fn (Blueprint $table) => $table->dropColumn(['refund_started_at', 'refunded_cents']));
    }
};
