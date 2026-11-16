<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Le bucket des images est-il configuré ? En production, une variable
 * MEDIA_DISK_* manquante ferait écrire les images sur le disque du conteneur
 * (perdues au déploiement suivant) ou enregistrer des URL privées. Les envois
 * sont alors refusés (503) ; le reste de l'API continue de fonctionner.
 * Hors production, le disque local sert de repli : rien n'est exigé.
 */
class MediaConfig
{
    /** Variables manquantes (noms seulement), vide si tout est en place ou hors production. */
    public static function missing(): array
    {
        if (!app()->environment('production')) {
            return [];
        }

        return array_keys(array_filter(
            (array) config('media.required_env', []),
            fn ($present) => !$present
        ));
    }

    public static function isReady(): bool
    {
        return self::missing() === [];
    }

    /** Erreur claire dans les logs, qui nomme les variables manquantes. */
    public static function reportMissing(string $context): void
    {
        $missing = self::missing();
        if ($missing === []) {
            return;
        }

        Log::error('Images désactivées : variables d\'environnement manquantes : ' . implode(', ', $missing)
            . '. Les envois d\'images renvoient 503 ; le reste de l\'API fonctionne.', [
                'missing' => $missing,
                'context' => $context,
            ]);
    }
}
