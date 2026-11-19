<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codes-barres. Additive et réversible : une colonne nullable sur articles et
 * sur variations, rien d'autre n'est modifié.
 *
 * Unicité « un code par compte » :
 * - articles a user_id (= id du compte) : index unique partiel
 *   (user_id, barcode) WHERE barcode IS NOT NULL — même SQL en PostgreSQL et
 *   en SQLite (les deux gèrent les index partiels).
 * - variations n'a pas de colonne de compte (le compte est celui de l'article
 *   parent), et un même code ne doit pas non plus servir à la fois à un
 *   article et à une variation : aucun index ne peut porter ça sans dupliquer
 *   le compte dans variations. L'unicité est donc garantie par
 *   App\Services\BarcodeService, qui verrouille la ligne du compte
 *   (users, lockForUpdate) avant de vérifier puis d'écrire. Un simple index
 *   sur variations.barcode sert la recherche par code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable();
        });
        Schema::table('variations', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable();
            $table->index('barcode', 'variations_barcode_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX articles_user_id_barcode_unique
             ON articles (user_id, barcode)
             WHERE barcode IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS articles_user_id_barcode_unique');

        Schema::table('variations', function (Blueprint $table) {
            $table->dropIndex('variations_barcode_index');
            $table->dropColumn('barcode');
        });
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};
