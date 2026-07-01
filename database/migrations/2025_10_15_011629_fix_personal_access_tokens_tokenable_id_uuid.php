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
        
        // Vérifier le type de la colonne tokenable_id
        $result = DB::selectOne("
            SELECT data_type 
            FROM information_schema.columns 
            WHERE table_name = 'personal_access_tokens' 
            AND column_name = 'tokenable_id'
        ");
        
        // Si la colonne existe et n'est pas déjà en uuid
        if ($result && $result->data_type !== 'uuid') {
            // Vérifier si la table est vide
            $count = DB::table('personal_access_tokens')->count();
            
            if ($count === 0) {
                // Si vide, supprimer l'index et la colonne, puis recréer
                DB::statement('DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index');
                DB::statement('ALTER TABLE personal_access_tokens DROP COLUMN tokenable_id');
                DB::statement('ALTER TABLE personal_access_tokens ADD COLUMN tokenable_id uuid');
                DB::statement('CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens (tokenable_type, tokenable_id)');
            }
            // Si la table n'est pas vide, on ne fait rien (nécessiterait une migration de données)
        }
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