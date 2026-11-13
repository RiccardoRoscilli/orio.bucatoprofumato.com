<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Annullo di una vendita gia' incassata: documento commerciale di annullo e rimborso carta.
            // Registrati separatamente, cosi' se uno dei due passaggi fallisce si riprova solo quello.
            $table->timestamp('receipt_voided_at')->nullable()->after('receipt_xml');
            $table->string('void_receipt_number', 30)->nullable()->after('receipt_voided_at');
            $table->timestamp('refunded_at')->nullable()->after('pos_transaction_id');
            $table->foreignId('voided_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['receipt_voided_at', 'void_receipt_number', 'refunded_at']);
        });
    }
};
