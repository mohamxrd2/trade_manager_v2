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
}
