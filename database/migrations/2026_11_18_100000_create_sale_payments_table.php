<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamenti di una vendita, anche a parti (due carte, carta + contanti). Le vendite gia'
 * incassate prima di questa tabella ricevono un pagamento unico ricostruito dalla vendita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20); // contanti | pos
            $table->unsignedInteger('amount_cents'); // importo richiesto
            $table->unsignedInteger('paid_cents')->default(0); // incassato (contanti: anche parziale)
            $table->string('status', 20)->default('in_corso'); // in_corso | pagato | annullato
            $table->boolean('manual')->default(false); // carta digitata a mano sul POS
            $table->string('terminal_id', 30)->nullable();
            $table->string('pos_order_id', 50)->nullable()->index();
            $table->string('pos_transaction_id', 50)->nullable();
            $table->string('vne_transaction_id')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('sales')->where('paid_cents', '>', 0)->whereNotNull('payment_method')->orderBy('id')
            ->each(function ($sale) use ($now) {
                DB::table('sale_payments')->insert([
                    'sale_id' => $sale->id,
                    'method' => $sale->payment_method === 'carta' ? 'pos' : $sale->payment_method,
                    'amount_cents' => $sale->paid_cents,
                    'paid_cents' => $sale->paid_cents,
                    'status' => 'pagato',
                    'manual' => $sale->payment_method === 'pos' && ! $sale->pos_transaction_id,
                    'terminal_id' => $sale->pos_terminal_id,
                    'pos_order_id' => $sale->pos_order_id,
                    'pos_transaction_id' => $sale->pos_transaction_id,
                    'vne_transaction_id' => $sale->vne_transaction_id,
                    'paid_at' => $sale->paid_at ?? $sale->updated_at,
                    'refunded_at' => $sale->refunded_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
