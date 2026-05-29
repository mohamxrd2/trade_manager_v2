<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table des lignes de facture.
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            // Clé primaire UUID
            $table->uuid('id')->primary();
            
            // Relation vers la facture
            $table->foreignUuid('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();
            
            // Relation vers l'article (nullable si article supprimé)
            $table->uuid('article_id')->nullable();
            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->nullOnDelete();
            
            // Snapshot des informations article au moment de la facture
            $table->string('name_snapshot');           // Nom de l'article
            $table->text('description_snapshot')->nullable(); // Description
            
            // Prix et quantité
            $table->decimal('unit_price', 15, 2);      // Prix unitaire
            $table->integer('quantity');                // Quantité
            $table->decimal('discount_percent', 5, 2)->default(0); // Remise ligne
            $table->decimal('discount_amount', 15, 2)->default(0); // Montant remise
            $table->decimal('total_line', 15, 2);      // Total ligne
            
            $table->timestamps();
            
            // Index
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
