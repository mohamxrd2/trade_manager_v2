<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'activité du compte — suppressions/restaurations
     * d'opérations, changements de prix, gestion d'équipe, connexions
     * membres (voir la liste complète dans le contexte du chantier
     * "Équipe"). `account_id` permet de lister tout le journal d'UN compte
     * (propriétaire + tous ses membres) en une requête ; `user_id` est
     * l'AUTEUR réel de l'action. Lecture réservée à la permission
     * `team.manage` (étape 3 — pas d'interface ici).
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('account_id');
            $table->uuid('user_id');
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('account_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['account_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
