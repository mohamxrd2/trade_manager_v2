<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chantier "Équipe" étape 2 : une personne de l'équipe (associé et/ou
     * membre) a un e-mail de contact au même titre qu'un téléphone — déjà
     * le cas pour un membre (users.email), manquant pour un associé
     * sans accès. Additive et réversible, aucune donnée existante touchée.
     */
    public function up(): void
    {
        Schema::table('collaborators', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('collaborators', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
