<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Modifie la contrainte de clé étrangère article_id pour utiliser 'set null' 
     * au lieu de 'cascade', afin de conserver les transactions même si l'article est supprimé.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Supprimer l'ancienne contrainte de clé étrangère
            $table->dropForeign(['article_id']);
            
            // Recréer la contrainte avec onDelete('set null')
            // Cela permettra de conserver les transactions avec article_id = NULL
            // quand l'article est supprimé
            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     * 
     * Restaure l'ancienne contrainte avec 'cascade' pour revenir au comportement initial.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Supprimer la contrainte actuelle
            $table->dropForeign(['article_id']);
            
            // Restaurer l'ancienne contrainte avec 'cascade'
            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->onDelete('cascade');
        });
    }
};
