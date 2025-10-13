<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary(); // UUID comme clé primaire
            $table->uuid('user_id'); // FK vers users.id (UUID)
            $table->uuid('article_id')->nullable(); // FK vers articles.id (UUID, nullable pour les dépenses)
            $table->string('name'); // Nom de la transaction
            $table->integer('quantity')->nullable(); // Quantité (null pour les dépenses)
            $table->decimal('amount', 15, 2); // Montant de la transaction
            $table->enum('type', ['sale', 'expense']); // Type de transaction
            $table->timestamps();
            
            // Clés étrangères
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('article_id')->references('id')->on('articles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
