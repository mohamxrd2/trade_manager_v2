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
            // Clé fournie par le client (un UUID généré une fois par soumission
            // utilisateur) pour détecter les retries automatiques d'une même
            // requête POST /api/transactions sans dépendre de la ressemblance
            // des champs métier. Nullable : les clients qui n'envoient pas la
            // clé (anciens clients, outils, autres intégrations) continuent de
            // fonctionner sans déduplication.
            $table->string('idempotency_key')->nullable()->after('type');

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
            $table->dropColumn('idempotency_key');
        });
    }
};
