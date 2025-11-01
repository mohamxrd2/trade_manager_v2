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
        
        // Supprimer les contraintes existantes
        DB::statement('ALTER TABLE personal_access_tokens DROP CONSTRAINT IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index');
        
        // Changer le type de tokenable_id de bigint à uuid
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING tokenable_id::uuid');
        
        // Recréer l'index
        DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supprimer l'index
        DB::statement('DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index');
        
        // Revenir au type bigint
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE bigint USING tokenable_id::bigint');
        
        // Recréer l'index
        DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
    }
};