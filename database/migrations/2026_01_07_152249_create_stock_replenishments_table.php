<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table pour l'historique des réapprovisionnements de stock.
     * Chaque ligne représente un ajout de stock à un article.
     */
    public function up(): void
    {
        Schema::create('stock_replenishments', function (Blueprint $table) {
            // Clé primaire UUID (cohérent avec le reste du projet)
            $table->uuid('id')->primary();
            
            // Clé étrangère vers l'utilisateur qui a effectué le réapprovisionnement
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            
            // Clé étrangère vers l'article réapprovisionné
            $table->foreignUuid('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();
            
            // Quantité ajoutée (minimum 1)
            $table->unsignedInteger('quantity_added');
            
            // Note optionnelle pour le réapprovisionnement
            $table->string('note')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Index pour optimiser les requêtes fréquentes
            $table->index(['article_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_replenishments');
    }
};
