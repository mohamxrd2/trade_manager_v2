<?php

namespace App\Providers;

use App\Support\RequestTimer;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TEMPORAIRE — instrumentation de diagnostic des 502/lenteurs en
        // prod, voir App\Support\RequestTimer. Singleton partagé entre
        // providers, middlewares, DB::listen() et contrôleurs.
        $this->app->singleton(RequestTimer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TEMPORAIRE — voir ci-dessus. Démarre le chrono le plus tôt
        // possible (avant le routing et les middlewares) et logue chaque
        // requête SQL exécutée pendant tout le cycle de vie de la requête,
        // taguée avec le request_id courant.
        $timer = $this->app->make(RequestTimer::class);
        $timer->start();

        DB::listen(function ($query) use ($timer) {
            $timer->mark('Requête SQL exécutée', [
                'sql' => $query->sql,
                'bindings_count' => count($query->bindings),
                'query_time_ms' => $query->time,
                'connection' => $query->connectionName,
            ]);

            // TEMPORAIRE — diagnostic. $query->time est mesuré par Laravel
            // au ras du driver PDO (temps moteur pur, hors connexion/réseau
            // avant la requête). Ligne dédiée, grep-able isolément
            // (indépendamment du bloc [TIMING-TABLE]), pour répondre
            // précisément à "quelles requêtes SQL dépassent 500 ms".
            if ($query->time > 500) {
                Log::warning('[TIMING] SQL LENTE (>500ms)', [
                    'request_id' => $timer->id(),
                    'query_time_ms' => $query->time,
                    'sql' => $query->sql,
                    'bindings_count' => count($query->bindings),
                    'connection' => $query->connectionName,
                ]);
            }
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
}
