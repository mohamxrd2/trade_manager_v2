<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestDatabaseConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Teste la connexion à la base de données';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Test de connexion
            $pdo = DB::connection()->getPdo();
            
            // Informations sur la connexion
            $database = DB::connection()->getDatabaseName();
            $driver = DB::connection()->getDriverName();
            $host = config('database.connections.' . config('database.default') . '.host');
            $port = config('database.connections.' . config('database.default') . '.port');
            
            $this->info('✅ Connexion à la base de données réussie!');
            $this->line('');
            $this->line('📊 Informations de connexion:');
            $this->line('   • Base de données: ' . $database);
            $this->line('   • Driver: ' . $driver);
            $this->line('   • Host: ' . $host);
            $this->line('   • Port: ' . $port);
            
            // Test d'une requête simple
            $result = DB::select('SELECT 1 as test');
            if ($result) {
                $this->line('   • Test de requête: ✅ Réussi');
            }
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error('❌ Erreur de connexion à la base de données');
            $this->line('');
            $this->line('🔍 Détails de l\'erreur:');
            $this->line('   ' . $e->getMessage());
            $this->line('');
            $this->line('💡 Vérifiez votre configuration dans le fichier .env');
            
            return 1;
        }
    }
}
