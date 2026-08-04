<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour gérer les erreurs de connexion à la base de données.
 * 
 * Ce middleware vérifie la disponibilité de la base de données et gère
 * les erreurs de connexion de manière gracieuse en retournant une réponse
 * JSON appropriée au lieu d'une erreur 500 cassée.
 */
class DatabaseConnectionMiddleware
{
    /**
     * Nombre maximum de tentatives de connexion.
     */
    protected int $maxRetries;

    /**
     * Délai entre les tentatives en millisecondes.
     */
    protected int $retryDelay;

    public function __construct()
    {
        $this->maxRetries = (int) config('database.connections.pgsql.retry_attempts', 3);
        $this->retryDelay = (int) config('database.connections.pgsql.retry_delay', 100);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // TEMPORAIRE — diagnostic des 502 en prod : ce middleware est LE
        // point de blocage suspecté (retry_attempts x DB_TIMEOUT peut
        // bloquer jusqu'à ~15s par requête sur un process mono-worker).
        $start = microtime(true);

        // Vérifier la connexion DB avec retry automatique
        $ok = $this->checkDatabaseConnectionWithRetry($request->path());

        Log::info('[DIAG] DatabaseConnectionMiddleware terminé', [
            'path' => $request->path(),
            'db_check_ok' => $ok,
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
        ]);

        if (!$ok) {
            return $this->databaseUnavailableResponse();
        }

        return $next($request);
    }

    /**
     * Vérifie la connexion à la base de données avec retry automatique.
     */
    protected function checkDatabaseConnectionWithRetry(string $path = ''): bool
    {
        $attempts = 0;

        while ($attempts < $this->maxRetries) {
            $attemptStart = microtime(true);
            try {
                // TEMPORAIRE — instrumentation de diagnostic : déterminer si
                // les ~426ms mesurées sur getPdo() sont payées à CHAQUE
                // requête ou uniquement sur la première requête de chaque
                // worker PHP-FPM.
                //
                // Pas de comparaison "avant/après" en mémoire (via une
                // propriété statique) : PHP réinitialise entièrement l'espace
                // mémoire "userland" — classes, statics, variables — à CHAQUE
                // requête, même quand le même worker/process OS traite les
                // requêtes successives (vérifié empiriquement : un flag
                // statique testé localement restait à false sur 5 requêtes
                // consécutives confirmées comme traitées par le même PID).
                // Seuls des mécanismes bas niveau explicitement conçus pour
                // ça survivent au-delà d'une requête — dont le pool de
                // connexions persistantes de PDO (PDO::ATTR_PERSISTENT),
                // justement ce qu'on cherche à observer ici.
                //
                // On logue donc uniquement les FAITS BRUTS de CETTE requête ;
                // la comparaison entre requêtes se fait a posteriori, en
                // regroupant les lignes de log par php_fpm_worker_pid une
                // fois les 20 requêtes de test effectuées : PID identique +
                // pg_backend_pid_courant identique + getpdo_duration_ms bas
                // => connexion réellement réutilisée par ce worker.
                $workerPid = getmypid();

                // Tente une requête simple pour vérifier la connexion
                $getPdoStart = microtime(true);
                $pdo = DB::connection()->getPdo();
                $getPdoDurationMs = round((microtime(true) - $getPdoStart) * 1000, 1);

                // pg_backend_pid() : PID du process PostgreSQL/Neon qui gère
                // CETTE connexion physique — fait vérifiable côté serveur,
                // pas une déduction basée sur le temps écoulé. Coût mesuré
                // séparément pour ne pas fausser getpdo_duration_ms.
                $pgBackendPidStart = microtime(true);
                $currentPgBackendPid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
                $pgBackendPidQueryMs = round((microtime(true) - $pgBackendPidStart) * 1000, 1);

                Log::info('[DB-CONN-DIAG] getPdo() — faits bruts pour cette requête', [
                    'php_fpm_worker_pid' => $workerPid,
                    'getpdo_duration_ms' => $getPdoDurationMs,
                    'pdo_attr_persistent_actif_sur_ce_handle' => $pdo->getAttribute(\PDO::ATTR_PERSISTENT),
                    'pg_backend_pid_courant' => $currentPgBackendPid,
                    'cout_diagnostic_pg_backend_pid_ms' => $pgBackendPidQueryMs,
                ]);

                Log::info('[DIAG] DatabaseConnectionMiddleware: connexion OK', [
                    'path' => $path,
                    'attempt' => $attempts + 1,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                ]);

                return true;
            } catch (\PDOException $e) {
                $attempts++;

                Log::warning("Tentative de connexion DB échouée ({$attempts}/{$this->maxRetries})", [
                    'path' => $path,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                if ($attempts < $this->maxRetries) {
                    // Attendre avant la prochaine tentative
                    usleep($this->retryDelay * 1000);
                }
            } catch (\Exception $e) {
                Log::error("Erreur inattendue lors de la connexion DB", [
                    'path' => $path,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return false;
            }
        }

        Log::error("Base de données indisponible après {$this->maxRetries} tentatives", ['path' => $path]);
        return false;
    }

    /**
     * Retourne une réponse JSON propre quand la DB est indisponible.
     */
    protected function databaseUnavailableResponse(): Response
    {
        return response()->json([
            'success' => false,
            'error' => 'service_unavailable',
            'message' => 'Le service de base de données est temporairement indisponible. Veuillez réessayer dans quelques instants.',
            'details' => [
                'type' => 'database_connection_error',
                'retry_after' => 30, // Suggérer de réessayer après 30 secondes
            ],
        ], 503, [
            'Retry-After' => 30,
        ]);
    }
}

