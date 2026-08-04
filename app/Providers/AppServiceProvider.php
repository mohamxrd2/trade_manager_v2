<?php

namespace App\Providers;

use App\Support\RequestTimer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TEMPORAIRE — instrumentation de diagnostic des requêtes >6s, voir
        // App\Support\RequestTimer. Singleton partagé entre providers,
        // middlewares, DB::listen() et contrôleurs.
        $this->app->singleton(RequestTimer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TEMPORAIRE — voir ci-dessus. Marque le point d'entrée de CE
        // provider (indépendamment de savoir s'il boot avant ou après
        // DatabaseServiceProvider — les deux marquent leur propre entrée,
        // les données réelles révéleront l'ordre exact) et logue chaque
        // requête SQL exécutée pendant tout le cycle de vie de la requête.
        $timer = $this->app->make(RequestTimer::class);
        $timer->mark(RequestTimer::CAT_BOOTSTRAP, 'Entrée AppServiceProvider::boot()');

        DB::listen(function ($query) use ($timer) {
            // $query->time est mesuré par Laravel au ras du driver PDO
            // (temps moteur pur côté Postgres, hors connexion réseau
            // établie avant la requête) — since_last_ms (calculé par
            // mark()) inclut lui la latence réseau/aller-retour complète
            // vers Neon, les deux valeurs sont donc complémentaires.
            $timer->mark(RequestTimer::CAT_SQL, $this->shortenSql($query->sql), [
                'sql_full' => $query->sql,
                'query_time_ms' => $query->time,
                'bindings_count' => count($query->bindings),
                'connection' => $query->connectionName,
            ]);

            // TEMPORAIRE — tableau SQL dédié (voir RequestTimer::logSqlQuery
            // / renderSqlTable). Purement additif, ne change rien au
            // comportement : identifie le fichier applicatif et le
            // contrôleur à l'origine de CETTE requête SQL précise.
            $origin = $this->resolveQueryOrigin();
            $route = app('request')->route();
            $controller = ($route !== null && is_string($route->getActionName()))
                ? $route->getActionName()
                : null;

            $timer->logSqlQuery(
                $query->sql,
                $query->time,
                $origin['file'] ?? null,
                $origin['line'] ?? null,
                $controller
            );
        });

        // En production, forcer HTTPS si nécessaire
        if (env('APP_ENV') === 'production' && env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }

        // Configuration des cookies pour le développement local
        // En production, SameSite=None nécessite Secure=true (HTTPS)
        if (env('APP_ENV') === 'local') {
            // Pour le développement local, on peut utiliser 'lax' ou 'none'
            // 'lax' est plus sécurisé mais peut poser problème avec CORS
            // 'none' nécessite Secure=true qui nécessite HTTPS
            // On laisse la configuration par défaut dans config/session.php
        }
    }

    /**
     * TEMPORAIRE — voir ci-dessus. Tronque le SQL pour la lisibilité du
     * tableau chronologique (le SQL complet reste dans le contexte du log
     * [TIMING] individuel de chaque requête, non tronqué).
     */
    private function shortenSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        return strlen($sql) > 60 ? substr($sql, 0, 57) . '...' : $sql;
    }

    /**
     * TEMPORAIRE — voir DB::listen() ci-dessus. Remonte la pile d'appel
     * depuis ce listener (interne à Illuminate\Database) jusqu'au premier
     * frame situé dans app/ : c'est le code applicatif (contrôleur,
     * modèle, service...) qui a réellement déclenché cette requête SQL,
     * par opposition aux frames internes de Illuminate\Database\Connection
     * qui n'apprendraient rien.
     */
    private function resolveQueryOrigin(): ?array
    {
        $appPath = app_path() . DIRECTORY_SEPARATOR;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            if (!isset($frame['file']) || !str_starts_with($frame['file'], $appPath)) {
                continue;
            }

            // Ce listener DB::listen() est lui-même défini dans ce fichier
            // (app/Providers/AppServiceProvider.php) : sans cette exclusion,
            // le premier frame "dans app/" serait systématiquement lui-même
            // plutôt que le vrai code appelant (contrôleur, modèle...).
            if ($frame['file'] === __FILE__) {
                continue;
            }

            return [
                'file' => str_replace(base_path() . DIRECTORY_SEPARATOR, '', $frame['file']),
                'line' => $frame['line'] ?? null,
            ];
        }

        return null;
    }
}
