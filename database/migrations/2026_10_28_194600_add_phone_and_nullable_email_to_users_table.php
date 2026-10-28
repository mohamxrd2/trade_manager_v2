<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Chantier "Équipe" : un membre n'a pas forcément d'email (identifiant =
     * username, pas email — voir User::username) ni de téléphone connu à la
     * création. `email` était NOT NULL + unique depuis la création de la
     * table ; on relâche la contrainte NOT NULL (raw SQL, pas
     * ->nullable()->change(), pour ne pas dépendre de doctrine/dbal) — elle
     * reste unique, et NULL n'entre jamais en conflit avec l'unicité
     * (plusieurs lignes NULL sont autorisées par un index unique Postgres).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
        });
    }

    /**
     * Réversible UNIQUEMENT si aucun membre sans email n'a été créé entre
     * temps (sinon SET NOT NULL échoue sur les lignes NULL existantes) —
     * comportement standard pour un rollback, pas une garantie absolue.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
