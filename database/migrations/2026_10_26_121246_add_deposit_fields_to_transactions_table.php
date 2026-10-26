<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive : colonnes nullable pour les apports de fonds (type "apport")
     * et retraits du gérant (type "retrait") — voir la migration suivante
     * pour l'ajout des valeurs elles-mêmes à la contrainte sur `type`,
     * isolée dans son propre fichier/transaction (même précaution que
     * 2026_10_12_000000/000001 : incidents Neon documentés sur des ALTER
     * TABLE combinés touchant une contrainte).
     *
     * article_id/variable_id restent NULL pour un apport/retrait — déjà
     * nullable depuis l'origine, aucune colonne supplémentaire requise pour
     * cette règle.
     *
     * Défensive (hasColumn) : permet de rejouer cette migration sans erreur
     * si un déploiement précédent a partiellement appliqué ces colonnes.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'funding_source')) {
                // Origine d'un apport : "apport_personnel", "collaborateur",
                // "pret", "autre" — chaîne libre plutôt qu'un enum Postgres,
                // pour rester aussi simple à étendre que le reste du modèle.
                $table->string('funding_source')->nullable()->after('type');
            }

            if (!Schema::hasColumn('transactions', 'note')) {
                $table->text('note')->nullable()->after('funding_source');
            }

            if (!Schema::hasColumn('transactions', 'payment_method')) {
                // Moyen d'encaissement de CETTE transaction — distinct de
                // clients.payment_method (préférence générale d'un client,
                // vocabulaire différent : cash/credit_card/bank_transfer/...).
                // Servira aussi à la fonctionnalité "moyen d'encaissement" à
                // venir sur les ventes/dépenses.
                $table->string('payment_method')->nullable()->after('note');
            }
        });

        if (!Schema::hasColumn('transactions', 'collaborator_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Apport provenant d'un associé précis. nullOnDelete : si le
                // collaborateur est retiré de l'équipe plus tard, l'apport
                // historique reste (jamais supprimé), seul le lien se détache.
                $table->uuid('collaborator_id')->nullable()->after('payment_method');
                $table->foreign('collaborator_id')->references('id')->on('collaborators')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'collaborator_id')) {
                $table->dropForeign(['collaborator_id']);
                $table->dropColumn('collaborator_id');
            }
            if (Schema::hasColumn('transactions', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('transactions', 'note')) {
                $table->dropColumn('note');
            }
            if (Schema::hasColumn('transactions', 'funding_source')) {
                $table->dropColumn('funding_source');
            }
        });
    }
};
