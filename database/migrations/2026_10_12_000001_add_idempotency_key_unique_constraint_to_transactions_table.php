<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Séparée de l'ajout de colonne (migration précédente, 2026_10_12_000000)
     * dans sa propre transaction Laravel : deux déploiements ont échoué sur
     * Neon avec SQLSTATE[25P02] portant précisément sur cette contrainte,
     * alors que la migration entière rejoue sans erreur sur PostgreSQL 18.6
     * identique en local. Isoler chaque ALTER TABLE dans sa propre
     * transaction (transaction Laravel toujours active, non désactivée)
     * évite qu'un incident transitoire sur l'une fasse échouer l'autre en
     * cascade, et rend visible dans migrate:status laquelle échoue
     * réellement si le problème se reproduit.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Unique par utilisateur (et non globalement) : deux utilisateurs
            // différents pourraient en théorie générer la même valeur côté
            // client sans que ça pose problème. NULL est traité comme distinct
            // de toute autre valeur par Postgres (et SQLite) pour une
            // contrainte UNIQUE : un nombre arbitraire de lignes avec
            // idempotency_key NULL est donc autorisé pour un même utilisateur.
            $table->unique(['user_id', 'idempotency_key'], 'transactions_user_id_idempotency_key_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_id_idempotency_key_unique');
        });
    }
};
