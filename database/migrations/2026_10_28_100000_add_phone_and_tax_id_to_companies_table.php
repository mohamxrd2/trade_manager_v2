<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute téléphone et numéro RCCM/contribuable à l'entreprise — affichés
     * sur le ticket de caisse (chantier ticket-caisse). Migration additive :
     * colonnes nullable, aucune donnée existante touchée.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('tax_id')->nullable()->after('bank_account_number'); // RCCM ou n° contribuable
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['phone', 'tax_id']);
        });
    }
};
