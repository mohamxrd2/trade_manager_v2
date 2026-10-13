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
     *
     * Défensive (ajouté après un nouvel échec) : un déploiement a laissé
     * public.migrations avec 2026_10_12_000000 marquée Ran sur Neon alors
     * que la colonne qu'elle est censée avoir créée n'existe pas réellement
     * (constaté directement en lecture seule — cause exacte non établie).
     * Laravel ne réexécute jamais une migration déjà enregistrée comme Ran,
     * et public.migrations ne doit pas être modifiée à la main : cette
     * migration (encore Pending) vérifie donc l'état réel du schéma — pas
     * l'historique Laravel — avant d'agir, et répare la colonne manquante
     * si besoin avant de poser la contrainte. Idempotente dans les deux
     * sens : sans effet si la colonne et/ou la contrainte existent déjà
     * (nouvelle base saine, ou nouvelle tentative après un échec partiel),
     * agit uniquement sur ce qui manque réellement.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transactions', 'idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Même définition que dans 2026_10_12_000000 : clé fournie par
                // le client (UUID généré une fois par soumission) pour
                // détecter les retries automatiques sans dépendre de la
                // ressemblance des champs métier.
                $table->string('idempotency_key')->nullable()->after('type');
            });
        }

        if (!Schema::hasIndex('transactions', 'transactions_user_id_idempotency_key_unique', 'unique')) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('transactions', 'transactions_user_id_idempotency_key_unique', 'unique')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique('transactions_user_id_idempotency_key_unique');
            });
        }
    }
};
