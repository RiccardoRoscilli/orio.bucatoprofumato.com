<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contenuto dei cesti (ricetta che arriva da teaerp): serve a trovare un cesto cercando un prodotto che c'è dentro
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('name');
            $table->decimal('qty', 10, 3)->default(1);
            $table->timestamps();
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_components');
    }
};
