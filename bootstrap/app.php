<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\DatabaseServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middleware pour les routes API
        $middleware->api(prepend: [
            // 1. Force les réponses JSON (doit être en premier)
            \App\Http\Middleware\ForceJsonResponse::class,
            // 2. Gestion CORS (doit être avant tout autre middleware)
            \Illuminate\Http\Middleware\HandleCors::class,
            // 3. Gestion des connexions DB
            \App\Http\Middleware\DatabaseConnectionMiddleware::class,
            // 4. Authentification Sanctum SPA
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Alias pour les middlewares personnalisés
        $middleware->alias([
            'company.ready' => \App\Http\Middleware\EnsureCompanyInvoiceReady::class,
            // TEMPORAIRE — diagnostic des 502 en prod, voir LogApiDiagnostics.
            'diag.log' => \App\Http\Middleware\LogApiDiagnostics::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Gestion des erreurs de connexion PostgreSQL
        $exceptions->render(function (\PDOException $e, Request $request) {
            // Vérifier si c'est une erreur de connexion (timeout, serveur down, etc.)
            $connectionErrors = ['08006', '08001', '08003', '08004', '08007', '57P01', '57P02', '57P03'];
            $sqlState = $e->getCode();

            if (in_array($sqlState, $connectionErrors) || str_contains($e->getMessage(), 'timeout')) {
                \Illuminate\Support\Facades\Log::error('Erreur de connexion PostgreSQL', [
                    'error' => $e->getMessage(),
                    'code' => $sqlState,
                    'host' => config('database.connections.pgsql.host'),
                ]);

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json([
                        'success' => false,
                        'error' => 'database_connection_error',
                        'message' => 'Le service de base de données est temporairement indisponible.',
                        'details' => [
                            'type' => 'connection_timeout',
                            'retry_after' => 30,
                        ],
                    ], 503, ['Retry-After' => 30]);
                }
            }

            return null; // Laisser Laravel gérer les autres erreurs PDO
        });

        // Gestion des QueryException (erreurs de requêtes DB)
        $exceptions->render(function (QueryException $e, Request $request) {
            $previous = $e->getPrevious();
            
            // Si l'erreur sous-jacente est une PDOException de connexion
            if ($previous instanceof \PDOException) {
                $connectionErrors = ['08006', '08001', '08003', '08004', '08007', '57P01', '57P02', '57P03'];
                $sqlState = $previous->getCode();

                if (in_array($sqlState, $connectionErrors) || str_contains($e->getMessage(), 'timeout')) {
                    \Illuminate\Support\Facades\Log::error('Erreur de requête DB - connexion perdue', [
                        'error' => $e->getMessage(),
                        'query' => $e->getSql() ?? 'N/A',
                    ]);

                    if ($request->expectsJson() || $request->is('api/*')) {
                        return response()->json([
                            'success' => false,
                            'error' => 'database_connection_error',
                            'message' => 'La connexion à la base de données a été perdue.',
                            'details' => [
                                'type' => 'query_connection_error',
                                'retry_after' => 30,
                            ],
                        ], 503, ['Retry-After' => 30]);
                    }
                }
            }

            return null; // Laisser Laravel gérer les autres QueryException
        });

        // Gestion générique des erreurs 503 (Service Unavailable)
        $exceptions->render(function (ServiceUnavailableHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'error' => 'service_unavailable',
                    'message' => $e->getMessage() ?: 'Le service est temporairement indisponible.',
                    'details' => [
                        'retry_after' => 30,
                    ],
                ], 503, ['Retry-After' => 30]);
            }

            return null;
        });
    })->create();
