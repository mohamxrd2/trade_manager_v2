<?php

namespace App\Providers;

use App\Support\RequestTimer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use PDOException;

/**
 * Service Provider pour la gestion robuste de la connexion à la base de données.
 * 
 * Ce provider gère:
 * - La vérification de la disponibilité de la DB au démarrage
 * - Le fallback automatique du SESSION_DRIVER vers 'file' si DB indisponible
 * - La configuration des timeouts et connexions persistantes
 */
class DatabaseServiceProvider extends ServiceProvider
{
    /**
     * Indique si la base de données est disponible.
     */
    protected static bool $databaseAvailable = true;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Enregistrer un singleton pour suivre l'état de la DB
        $this->app->singleton('database.status', function () {
            return new class {
                public bool $available = true;
                public ?string $lastError = null;
                public ?\DateTime $lastCheck = null;
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TEMPORAIRE — instrumentation diagnostic. Ce provider boot avant
        // le routing/les middlewares : c'est le point le plus précoce
        // disponible pour démarrer le chrono partagé.
        $this->app->make(RequestTimer::class)->start();

        // CORRECTIF (diagnostic 502) : checkDatabaseAndConfigureSession()
        // ouvrait une vraie connexion Neon (getPdo + SELECT 1) sur CHAQUE
        // requête, même web/console, avant même le routing — ~700ms mesurés
        // en local, cause principale du 502 sous charge avec le serveur
        // mono-thread (php artisan serve). Redondant avec
        // DatabaseConnectionMiddleware, qui fait déjà ce contrôle pour les
        // routes API avec retry + réponse 503 propre. Retiré ici ; la
        // méthode reste disponible (isDatabaseUp/recheckDatabase) si besoin
        // futur, simplement plus appelée automatiquement à chaque requête.
    }

    /**
     * Vérifie la connexion à la base de données et configure le fallback de session.
     */
    protected function checkDatabaseAndConfigureSession(): void
    {
        $sessionDriver = config('session.driver');
        
        // Si le session driver est 'database', vérifier que la DB est accessible
        if ($sessionDriver === 'database') {
            if (!$this->isDatabaseAvailable()) {
                $this->switchSessionToFile();
            }
        }
    }

    /**
     * Vérifie si la base de données est disponible.
     */
    protected function isDatabaseAvailable(): bool
    {
        $timer = $this->app->make(RequestTimer::class);
        $status = app('database.status');
        $status->lastCheck = new \DateTime();

        try {
            // Timeout court pour la vérification initiale
            $connection = DB::connection();

            // TEMPORAIRE — isole précisément le temps d'établissement de la
            // connexion PDO à Neon (handshake TCP + auth), séparément du
            // temps d'exécution du SELECT 1 juste après. getPdo() déclenche
            // la connexion réelle si elle n'est pas déjà établie.
            $connectStart = microtime(true);
            $pdo = $connection->getPdo();
            $timer->mark('Connexion PDO à Neon établie', [
                'connect_duration_ms' => round((microtime(true) - $connectStart) * 1000, 1),
            ]);

            // Test simple pour vérifier que la connexion fonctionne.
            // Appel PDO brut (pas DB::select) : DB::listen() ne le capte
            // pas, donc on le chronomètre manuellement ici.
            $queryStart = microtime(true);
            $pdo->query('SELECT 1');
            $timer->mark('SELECT 1 (vérification connexion) exécuté', [
                'query_duration_ms' => round((microtime(true) - $queryStart) * 1000, 1),
            ]);

            $status->available = true;
            $status->lastError = null;
            self::$databaseAvailable = true;
            
            return true;
        } catch (PDOException $e) {
            $status->available = false;
            $status->lastError = $e->getMessage();
            self::$databaseAvailable = false;

            Log::warning('Base de données indisponible au démarrage', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'host' => config('database.connections.pgsql.host'),
                'port' => config('database.connections.pgsql.port'),
            ]);

            return false;
        } catch (\Exception $e) {
            $status->available = false;
            $status->lastError = $e->getMessage();
            self::$databaseAvailable = false;

            Log::error('Erreur inattendue lors de la vérification de la DB', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Bascule le SESSION_DRIVER vers 'file' en cas d'indisponibilité de la DB.
     */
    protected function switchSessionToFile(): void
    {
        Log::warning('Basculement automatique du SESSION_DRIVER vers "file" car la base de données est indisponible.');

        // Modifier la configuration en runtime
        Config::set('session.driver', 'file');

        // S'assurer que le dossier des sessions existe
        $sessionPath = storage_path('framework/sessions');
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0755, true);
        }
    }

    /**
     * Vérifie si la base de données est disponible (méthode statique).
     */
    public static function isDatabaseUp(): bool
    {
        return self::$databaseAvailable;
    }

    /**
     * Force une nouvelle vérification de la disponibilité de la DB.
     */
    public static function recheckDatabase(): bool
    {
        try {
            DB::connection()->getPdo()->query('SELECT 1');
            self::$databaseAvailable = true;
            return true;
        } catch (\Exception $e) {
            self::$databaseAvailable = false;
            return false;
        }
    }
}

