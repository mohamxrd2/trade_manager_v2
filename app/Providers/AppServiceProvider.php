<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
