<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En-tête d'une opération (panier) — étape 1 du chantier "paniers
 * multi-articles" : `transactions` reste la table des LIGNES (article,
 * variation, quantité, montant), inchangée dans sa structure autre que
 * l'ajout de `operation_id` (migration suivante). Les analyses/stock/top
 * produits, qui agrègent des `transactions`, continuent de fonctionner sans
 * modification.
 *
 * Table neuve : le CHECK sur `type` (via enum()) est posé directement à la
 * création, pas besoin de la précaution "ALTER CONSTRAINT dans sa propre
 * migration" réservée aux modifications d'une contrainte EXISTANTE (voir
 * 2026_10_26_121300_update_transactions_type_check_constraint.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            // Compte propriétaire de l'opération — le gérant aujourd'hui,
            // potentiellement un membre d'équipe plus tard (nullable en
            // attendant cette fonctionnalité).
            $table->uuid('created_by_user_id')->nullable();
            $table->enum('type', ['sale', 'expense', 'apport', 'retrait']);
            $table->string('code');
            $table->enum('payment_method', ['especes', 'orange_money', 'moov_money', 'wave', 'mtn_money', 'virement', 'autre'])->nullable();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('amount_received', 12, 2)->nullable();
            $table->decimal('change_given', 12, 2)->nullable();
            $table->uuid('client_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->string('idempotency_key')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('set null');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('set null');

            $table->index(['user_id', 'occurred_at']);
            $table->unique(['user_id', 'code']);
            $table->index(['user_id', 'type']);
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
