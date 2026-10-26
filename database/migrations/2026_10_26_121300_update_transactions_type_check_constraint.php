<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `type` est un `enum()` Laravel — sur PostgreSQL, ceci se traduit par
     * une colonne varchar + une contrainte CHECK nommée
     * "transactions_type_check" (vérifié : aucun type ENUM natif Postgres
     * n'est utilisé ici). On supprime cette contrainte puis on la recrée
     * avec les deux nouvelles valeurs — jamais de perte de données, les
     * lignes existantes ('sale'/'expense') restent valides dans les deux cas.
     *
     * Isolée dans sa propre migration (pas combinée avec l'ajout de colonnes
     * de la migration précédente) : même précaution que
     * 2026_10_12_000000/000001, où deux ALTER TABLE combinés sur une
     * contrainte avaient échoué sur Neon (SQLSTATE 25P02) sans reproduire en
     * local.
     *
     * Ne s'applique qu'à PostgreSQL : SQLite (tests Pest, voir
     * tests/Feature/*.php) recrée son propre schéma minimal sans jamais
     * rejouer les migrations réelles (RefreshDatabase désactivé dans tout le
     * projet, cf. tests/Pest.php) — cette migration ne tourne donc en
     * pratique que sur `waelly_local`/`waelly_test`/Neon.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_type_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('sale', 'expense', 'apport', 'retrait'))");
    }

    /**
     * Reverse the migrations.
     *
     * Best-effort : si des lignes 'apport'/'retrait' existent déjà, recréer
     * l'ancienne contrainte à 2 valeurs échouera (violation CHECK) — c'est
     * le comportement attendu, pas un bug : on ne peut pas revenir à une
     * contrainte plus stricte que les données réelles sans d'abord purger ou
     * migrer ces lignes.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_type_check');
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_type_check CHECK (type IN ('sale', 'expense'))");
    }
};
