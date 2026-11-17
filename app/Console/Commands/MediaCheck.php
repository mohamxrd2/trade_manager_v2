<?php

namespace App\Console\Commands;

use App\Support\MediaConfig;
use Illuminate\Console\Command;

/**
 * Appelée au démarrage du conteneur (docker/entrypoint.sh) : signale dans les
 * logs une configuration incomplète du bucket des images. Ne bloque jamais le
 * démarrage (code de sortie 0) : ventes et stock fonctionnent sans images.
 */
class MediaCheck extends Command
{
    protected $signature = 'media:check';

    protected $description = 'Vérifie que les variables MEDIA_DISK_* du bucket des images sont définies (production)';

    public function handle(): int
    {
        $missing = MediaConfig::missing();
        if ($missing === []) {
            $this->info('Images : configuration complète.');

            return self::SUCCESS;
        }

        MediaConfig::reportMissing('démarrage');
        $this->error('Images désactivées : variables d\'environnement manquantes : ' . implode(', ', $missing)
            . '. POST /api/media renverra 503 ; le reste de l\'API fonctionne.');

        return self::SUCCESS;
    }
}
