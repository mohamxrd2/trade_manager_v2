<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table des clients pour la facturation.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            // Clé primaire UUID
            $table->uuid('id')->primary();
            
            // Propriétaire du client
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            
            // Informations client
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            
            // Méthode de paiement préférée
            $table->enum('payment_method', [
                'cash',
                'credit_card', 
                'bank_transfer',
                'cheque',
                'mobile_money',
                'other'
            ])->default('cash');
            
            // Adresses
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            
            // Notes internes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Index pour optimisation
            $table->index(['user_id', 'name']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
