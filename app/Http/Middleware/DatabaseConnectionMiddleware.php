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
        // Vérifier la connexion DB avec retry automatique
        if (!$this->checkDatabaseConnectionWithRetry()) {
            return $this->databaseUnavailableResponse();
        }

        return $next($request);
    }

    /**
     * Vérifie la connexion à la base de données avec retry automatique.
     */
    protected function checkDatabaseConnectionWithRetry(): bool
    {
        $attempts = 0;

        while ($attempts < $this->maxRetries) {
            try {
                // Tente une requête simple pour vérifier la connexion
                DB::connection()->getPdo();
                return true;
            } catch (\PDOException $e) {
                $attempts++;
                
                Log::warning("Tentative de connexion DB échouée ({$attempts}/{$this->maxRetries})", [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                if ($attempts < $this->maxRetries) {
                    // Attendre avant la prochaine tentative
                    usleep($this->retryDelay * 1000);
                }
            } catch (\Exception $e) {
                Log::error("Erreur inattendue lors de la connexion DB", [
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        Log::error("Base de données indisponible après {$this->maxRetries} tentatives");
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

