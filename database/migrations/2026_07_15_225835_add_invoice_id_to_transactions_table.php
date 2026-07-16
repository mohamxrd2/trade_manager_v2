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
        Schema::table('transactions', function (Blueprint $table) {
            // Lien vers la facture ayant généré cette transaction de vente.
            // Nullable : la plupart des transactions (ventes manuelles, dépenses)
            // ne sont pas liées à une facture. nullOnDelete pour ne jamais perdre
            // l'historique de vente même si la facture est supprimée.
            $table->foreignUuid('invoice_id')
                ->nullable()
                ->after('variable_id')
                ->constrained('invoices')
                ->nullOnDelete();

            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
