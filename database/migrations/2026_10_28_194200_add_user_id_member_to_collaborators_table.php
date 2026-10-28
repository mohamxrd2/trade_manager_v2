<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un associé (part dans l'entreprise) peut AUSSI avoir un accès à
     * l'application — les deux notions sont indépendantes (voir le contexte
     * du chantier "Équipe"). Nullable : un associé sans accès reste tel
     * quel, rien ne change pour le calcul des parts existant.
     */
    public function up(): void
    {
        Schema::table('collaborators', function (Blueprint $table) {
            $table->uuid('user_id_member')->nullable()->after('user_id');
            $table->foreign('user_id_member')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('collaborators', function (Blueprint $table) {
            $table->dropForeign(['user_id_member']);
            $table->dropColumn('user_id_member');
        });
    }
};
