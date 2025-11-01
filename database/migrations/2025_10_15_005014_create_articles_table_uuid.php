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
        
        Schema::create('articles_uuid', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('name');
            $table->decimal('sale_price', 15, 2);
            $table->integer('quantity');
            $table->enum('type', ['simple', 'variable']);
            $table->string('image')->nullable();
            $table->timestamps();
            
            // Clé étrangère vers users_uuid.id
            $table->foreign('user_id')->references('id')->on('users_uuid')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles_uuid');
    }
};