<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // La contrainte globale empêchait deux utilisateurs différents
            // d'avoir tous les deux "INV-2026-00001" — alors que
            // Invoice::generateInvoiceNumber() numérote par utilisateur.
            $table->dropUnique('invoices_invoice_number_unique');
            $table->unique(['user_id', 'invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'invoice_number']);
            $table->unique('invoice_number');
        });
    }
};
