<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Code d'entreprise, dérivé du nom puis FIXÉ — sert de préfixe à
     * l'identifiant de connexion des membres ({CODE}-{PRÉNOM}, étape 5).
     * Nullable ici : la génération + reprise pour les comptes existants se
     * fait via `php artisan team:backfill` (dry-run par défaut), jamais
     * dans la migration elle-même (même convention que operations:backfill/
     * BackfillVariations — une migration de schéma ne doit pas décider d'une
     * règle métier comme la dérivation du code).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
