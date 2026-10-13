<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ajoute uniquement la colonne, dans sa propre transaction Laravel. La
     * contrainte unique vit dans la migration suivante (2026_10_12_000001) :
     * deux déploiements ont échoué sur Neon avec SQLSTATE[25P02] portant
     * précisément sur cette contrainte alors que la migration entière rejoue
     * sans erreur sur PostgreSQL 18.6 identique en local — isoler chaque
     * ALTER TABLE dans sa propre transaction évite qu'un incident transitoire
     * sur l'une fasse échouer l'autre en cascade, et rend visible dans
     * migrate:status laquelle échoue réellement si le problème se reproduit.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Clé fournie par le client (un UUID généré une fois par soumission
            // utilisateur) pour détecter les retries automatiques d'une même
            // requête POST /api/transactions sans dépendre de la ressemblance
            // des champs métier. Nullable : les clients qui n'envoient pas la
            // clé (anciens clients, outils, autres intégrations) continuent de
            // fonctionner sans déduplication.
            $table->string('idempotency_key')->nullable()->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
