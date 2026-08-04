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
        // TEMPORAIRE — instrumentation de diagnostic des requêtes >6s, voir
        // App\Support\RequestTimer. Ce provider boot avant le routing et
        // les middlewares : les marks ci-dessous mesurent explicitement le
        // coût de checkDatabaseAndConfigureSession(), qui ouvre une
        // CONNEXION NEON RÉELLE (getPdo + SELECT 1) à ce stade — donc
        // avant même que DatabaseConnectionMiddleware ne fasse, plus tard
        // dans le pipeline, sa propre vérification de connexion. Mesure
        // uniquement : le comportement n'est pas modifié ici.
        $timer = $this->app->make(RequestTimer::class);
        $timer->mark(RequestTimer::CAT_BOOTSTRAP, 'Entrée DatabaseServiceProvider::boot()');

        // Vérifier la connexion DB et configurer le fallback de session si nécessaire
        // Désactivé : ~880ms/requête (getPdo+SELECT 1 vers Neon), redondant avec
        // DatabaseConnectionMiddleware et les gestionnaires PDOException/QueryException
        // de bootstrap/app.php. Aucun appel externe à checkDatabaseAndConfigureSession(),
        // isDatabaseAvailable(), switchSessionToFile(), recheckDatabase() ou isDatabaseUp()
        // ailleurs dans le projet (vérifié) — voir historique git pour réactiver.
        // $this->checkDatabaseAndConfigureSession();

        $timer->mark(RequestTimer::CAT_BOOTSTRAP, 'Sortie DatabaseServiceProvider::boot() (après checkDatabaseAndConfigureSession)');
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
        $status = app('database.status');
        $status->lastCheck = new \DateTime();

        try {
            // TEMPORAIRE — isole précisément le temps d'établissement de la
            // connexion PDO à Neon (handshake TCP+TLS+auth), séparément du
            // temps d'exécution du SELECT 1 juste après.
            $timer = $this->app->make(RequestTimer::class);

            // Timeout court pour la vérification initiale
            $connection = DB::connection();
            $connectStart = microtime(true);
            $pdo = $connection->getPdo();
            $timer->mark(RequestTimer::CAT_BOOTSTRAP, 'Connexion PDO à Neon établie (getPdo)', [
                'connect_duration_ms' => round((microtime(true) - $connectStart) * 1000, 1),
            ]);

            // Test simple pour vérifier que la connexion fonctionne.
            // Appel PDO brut (pas DB::select) : DB::listen() ne le capte
            // pas, donc chronomètre manuellement ici.
            $queryStart = microtime(true);
            $pdo->query('SELECT 1');
            $timer->mark(RequestTimer::CAT_BOOTSTRAP, 'SELECT 1 (vérification connexion) exécuté', [
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

