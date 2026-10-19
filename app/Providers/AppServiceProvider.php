<?php

namespace App\Providers;

use App\Models\Variation;
use App\Observers\VariationObserver;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Commandes destructrices qui ne doivent JAMAIS tourner ailleurs qu'en
     * local (migrate:fresh et db:wipe rasent tout le schéma ; db:seed
     * réinjecte des données de démo par-dessus des données réelles).
     */
    private const FORBIDDEN_OUTSIDE_LOCAL = ['migrate:fresh', 'db:wipe', 'db:seed'];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Variation::observe(VariationObserver::class);

        $this->guardStagingDatabase();
        $this->guardDestructiveCommandsOutsideLocal();

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
     * Arrêt immédiat si APP_ENV=staging mais que la base configurée n'est
     * PAS la base de staging attendue — filet de sécurité pour ne jamais
     * pointer "staging" vers la vraie base de production par erreur de
     * configuration (mauvaise variable d'environnement copiée/collée).
     *
     * STAGING_EXPECTED_DB_DATABASE permet de personnaliser le nom attendu ;
     * par défaut "waelly_staging" (celui documenté pour créer la base via
     * createdb — voir le guide de déploiement staging).
     */
    private function guardStagingDatabase(): void
    {
        if (!app()->environment('staging')) {
            return;
        }

        $expected = env('STAGING_EXPECTED_DB_DATABASE', 'waelly_staging');
        $actual = config('database.connections.pgsql.database');

        if ($actual !== $expected) {
            fwrite(STDERR, "\n🛑 ARRÊT : APP_ENV=staging mais DB_DATABASE=\"{$actual}\" ne correspond pas à la base de staging attendue (\"{$expected}\").\n");
            fwrite(STDERR, "   Ceci évite qu'une configuration staging mal copiée ne pointe vers la production.\n");
            fwrite(STDERR, "   Vérifiez DB_DATABASE dans .env.staging (ou STAGING_EXPECTED_DB_DATABASE si le nom est différent).\n\n");
            exit(1);
        }
    }

    /**
     * Bloque migrate:fresh / db:wipe / db:seed en dehors de APP_ENV=local,
     * avant même que la commande ne s'exécute (donc avant tout dégât).
     * Ces commandes ont leur propre ConfirmableTrait Laravel qui ne protège
     * QUE "production" par défaut — staging n'est pas couvert sans ceci.
     */
    private function guardDestructiveCommandsOutsideLocal(): void
    {
        Event::listen(function (CommandStarting $event) {
            if (!in_array($event->command, self::FORBIDDEN_OUTSIDE_LOCAL, true)) {
                return;
            }

            if (app()->environment('local')) {
                return;
            }

            $event->output->writeln("\n<error>🛑 \"{$event->command}\" est interdite en dehors de APP_ENV=local (environnement actuel : " . app()->environment() . ").</error>");
            $event->output->writeln('<error>   Cette commande efface ou réinjecte des données — jamais hors local.</error>');

            throw new \RuntimeException("Commande destructrice \"{$event->command}\" bloquée : APP_ENV doit être \"local\" (actuel : " . app()->environment() . ').');
        });
    }
}
