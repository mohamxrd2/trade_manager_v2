<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Active l'extension PostgreSQL `unaccent`, utilisée par
 * TransactionController::index() pour la recherche texte insensible aux
 * accents (« dore » trouve « Doré »). Vérifiée disponible sur Neon (doc
 * officielle : unaccent 1.1, pg_trgm 1.6, toutes versions PG14-PG18
 * supportées) ET en local (`pg_available_extensions`).
 *
 * Additive et réversible : `CREATE EXTENSION IF NOT EXISTS` /
 * `DROP EXTENSION IF EXISTS`, aucune donnée touchée. Ne s'applique qu'à
 * PostgreSQL — SQLite (tests Pest) n'a pas cette notion d'extension et
 * recrée de toute façon son propre schéma minimal sans jamais rejouer les
 * migrations réelles (voir tests/Feature/*.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
