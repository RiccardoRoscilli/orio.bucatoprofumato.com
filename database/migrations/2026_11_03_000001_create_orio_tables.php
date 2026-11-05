<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vat_code')->nullable()->index();
            $table->string('fiscal_code')->nullable();
            $table->string('address')->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('city')->nullable();
            $table->string('province', 5)->nullable();
            $table->string('country', 2)->default('IT');
            $table->string('sdi_code', 7)->nullable();
            $table->string('pec')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            // bozza, in_pagamento, pagata, scontrinata, fatturata, annullata
            $table->string('status')->default('bozza')->index();
            // scontrino | fattura
            $table->string('document_type')->default('scontrino');
            // contanti (VNE) | carta (VNE) | pos (POS esterno)
            $table->string('payment_method')->nullable();
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('paid_cents')->default(0);
            $table->string('vne_transaction_id')->nullable()->index();
            $table->string('receipt_number')->nullable();
            $table->text('receipt_xml')->nullable();
            $table->string('fattura24_doc_id')->nullable();
            $table->string('fattura24_doc_number')->nullable();
            $table->text('fattura24_xml')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('qty', 10, 3)->default(1);
            // prezzo unitario IVA inclusa, in centesimi
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedTinyInteger('vat_rate')->default(22);
            $table->timestamps();
        });

        // Log di ogni richiesta/risposta scambiata con la cassa automatica VNE
        Schema::create('vne_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vne_id')->nullable()->index();
            $table->unsignedSmallInteger('tipo');
            $table->json('request');
            $table->json('response')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('cash_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->date('day');
            $table->json('vne_data')->nullable();
            $table->unsignedInteger('orio_cash_cents')->default(0);
            $table->unsignedInteger('orio_card_cents')->default(0);
            $table->unsignedInteger('orio_pos_cents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closures');
        Schema::dropIfExists('vne_transactions');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
    }
};
