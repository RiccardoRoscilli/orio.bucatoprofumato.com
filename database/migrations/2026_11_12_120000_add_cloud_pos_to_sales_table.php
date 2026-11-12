<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Pagamento con carta inviato al POS (MultiSafepay Cloud POS): ordine in corso e transazione incassata
            $table->string('pos_order_id', 50)->nullable()->after('vne_transaction_id');
            $table->string('pos_transaction_id', 50)->nullable()->after('pos_order_id');
        });

        // Registro delle chiamate al POS, per riconciliare con la dashboard MultiSafepay
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_id', 50)->index();
            $table->string('action', 20);
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['pos_order_id', 'pos_transaction_id']);
        });
    }
};
