<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Activer l'extension UUID si pas déjà fait
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";');
        
        Schema::create('transactions_uuid', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('article_id')->nullable();
            $table->string('name');
            $table->integer('quantity')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('type', ['sale', 'expense']);
            $table->timestamps();
            
            // Clés étrangères
            $table->foreign('user_id')->references('id')->on('users_uuid')->onDelete('cascade');
            $table->foreign('article_id')->references('id')->on('articles_uuid')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions_uuid');
    }
};