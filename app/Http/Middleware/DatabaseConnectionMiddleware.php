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
                // Tente une requête simple pour vérifier la connexion
                DB::connection()->getPdo();

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

