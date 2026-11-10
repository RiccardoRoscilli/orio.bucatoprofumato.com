<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro delle chiamate a OpenAPI: l'account e' condiviso con altre app,
     * qui si contano quelle fatte da Orio per poterle rifatturare.
     */
    public function up(): void
    {
        Schema::create('openapi_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service', 40);
            $table->string('vat_code', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('live');
            $table->boolean('charged');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openapi_calls');
    }
};
