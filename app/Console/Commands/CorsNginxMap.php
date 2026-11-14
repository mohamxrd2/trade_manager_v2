<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Écrit la table des origines autorisées pour Nginx (map $http_origin),
 * à partir de la MÊME configuration que Laravel (config/cors.php). Appelée
 * au démarrage du conteneur (docker/entrypoint.sh) : les erreurs générées
 * par Nginx lui-même (413, 502, 504) portent alors les en-têtes CORS pour
 * ces origines seulement, et le navigateur affiche la vraie erreur.
 */
class CorsNginxMap extends Command
{
    protected $signature = 'cors:nginx-map {--output= : Fichier à écrire (sinon, affichage)}';

    protected $description = 'Affiche les origines CORS autorisées au format map de Nginx';

    public function handle(): int
    {
        $lines = $this->lines();
        $output = $this->option('output');

        if ($output) {
            file_put_contents($output, implode("\n", $lines) . "\n");
            $this->info(count($lines) . ' origine(s) écrite(s) dans ' . $output);
        } else {
            foreach ($lines as $line) {
                $this->line($line);
            }
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [];
        foreach (config('cors.allowed_origins', []) as $origin) {
            if (preg_match('#^https?://[A-Za-z0-9.:-]+$#', $origin)) {
                $lines[] = sprintf('"%s" "%s";', $origin, $origin);
            }
        }

        foreach (config('cors.allowed_origins_patterns', []) as $pattern) {
            // Motifs PHP « #^...$# » → regex Nginx « ~^...$ » (même syntaxe PCRE).
            if (preg_match('/^#(\^.*\$)#$/', $pattern, $m) && !str_contains($m[1], '"')) {
                $lines[] = sprintf('"~%s" $http_origin;', $m[1]);
            }
        }

        return $lines;
    }
}
