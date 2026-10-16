<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Avant cette colonne, "onboarding terminé" était purement déduit de
     * l'existence d'une Company + d'un UserSetting — cette inférence ne
     * permet pas de distinguer "jamais commencé" de "passé pour l'instant"
     * (les deux ressemblent à "aucune company/aucun setting"), ce que le
     * nouveau parcours d'onboarding a besoin de représenter explicitement.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('onboarding_status', ['pending', 'skipped', 'done'])
                ->default('pending')
                ->after('email_verified_at');
        });

        // Rétro-compatibilité : les comptes qui ont DÉJÀ une Company et des
        // UserSetting (créés avant ce champ, via l'ancien onboarding) sont
        // déjà passés par le parcours complet — sans ce backfill, ils
        // seraient tous renvoyés vers /onboarding dès la prochaine visite,
        // alors que `onboarding_status` par défaut vaut 'pending'.
        DB::table('users')
            ->whereIn('id', function ($query) {
                $query->select('user_id')->from('companies');
            })
            ->whereIn('id', function ($query) {
                $query->select('user_id')->from('user_settings');
            })
            ->update(['onboarding_status' => 'done']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_status');
        });
    }
};
