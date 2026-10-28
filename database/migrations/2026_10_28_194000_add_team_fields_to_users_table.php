<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chantier "Équipe", étape 1 — fondations du compte entreprise.
     *
     * `account_id` NULL = propriétaire (compte racine) ; sinon l'id du
     * propriétaire auquel ce membre est rattaché. `role` NULL pour un
     * propriétaire (son rôle est implicite : toutes les permissions, voir
     * Gate::before) — seuls les MEMBRES ont une valeur ('manager', 'cashier',
     * 'stock_manager', 'accountant'). Additive, 100% nullable/par défaut :
     * aucune ligne existante n'est modifiée par cette migration (tous les
     * comptes actuels restent des propriétaires, account_id/role NULL).
     *
     * `username` existe déjà (unique, non nul) depuis la création de la
     * table users — pas touché ici, voir le format {CODE}-{PRÉNOM} du
     * générateur de connexion membre (étape 5).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('account_id')->nullable()->after('id');
            $table->string('role')->nullable()->after('account_id');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->string('status')->default('active')->after('must_change_password');
            $table->timestamp('last_login_at')->nullable()->after('status');

            $table->foreign('account_id')->references('id')->on('users')->nullOnDelete();
            $table->index('account_id');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'role', 'must_change_password', 'status', 'last_login_at']);
        });
    }
};
