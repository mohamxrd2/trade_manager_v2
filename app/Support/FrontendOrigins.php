<?php

namespace App\Support;

/**
 * Origines du frontend autorisées, en UN seul endroit : config/cors.php
 * (CORS), config/sanctum.php (cookies de session) et docker/entrypoint.sh
 * (en-têtes CORS des erreurs générées par Nginx) partent de la même liste.
 *
 * - CORS_ALLOWED_ORIGINS : liste séparée par des virgules
 *   (ex. https://www.waelly.com,https://waelly.com,https://front-trade-manager.vercel.app).
 *   À défaut : FRONTEND_URL + ADDITIONAL_CORS_ORIGINS (ancienne configuration).
 * - Pour un domaine nu ou en www., l'autre variante est ajoutée d'office :
 *   waelly.com et www.waelly.com servent la même appli.
 * - http://localhost:3000 seulement en local et en test.
 * - Previews Vercel : seulement celles du projet et de l'équipe indiqués
 *   (CORS_VERCEL_PROJECT, CORS_VERCEL_SCOPE), jamais tout *.vercel.app.
 */
class FrontendOrigins
{
    /** @return list<string> */
    public static function origins(): array
    {
        $configured = self::csv(env('CORS_ALLOWED_ORIGINS'));
        if ($configured === []) {
            $configured = array_merge(self::csv(env('FRONTEND_URL')), self::csv(env('ADDITIONAL_CORS_ORIGINS')));
        }

        $local = self::isLocal();
        $origins = [];
        foreach ($configured as $origin) {
            $origin = self::normalize($origin);
            if ($origin === null || (!$local && self::isLocalhost($origin))) {
                continue;
            }
            $origins[] = $origin;
            if ($variant = self::wwwVariant($origin)) {
                $origins[] = $variant;
            }
        }

        if ($local) {
            $origins[] = 'http://localhost:3000';
            $origins[] = 'http://127.0.0.1:3000';
        }

        if ($project = self::vercelProject()) {
            $origins[] = "https://{$project}.vercel.app";
        }

        return array_values(array_unique($origins));
    }

    /**
     * Previews Vercel de CE projet : https://{projet}-{id ou git-branche}-{équipe}.vercel.app.
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        $project = self::vercelProject();
        $scope = strtolower(trim((string) env('CORS_VERCEL_SCOPE', '')));
        if (!$project || !preg_match('/^[a-z0-9-]+$/', $scope)) {
            return [];
        }

        return ['#^https://' . preg_quote($project, '#') . '-[a-z0-9-]+-' . preg_quote($scope, '#') . '\.vercel\.app$#'];
    }

    /**
     * Hôtes « stateful » pour Sanctum (cookies de session) : ceux de
     * SANCTUM_STATEFUL_DOMAINS plus ceux des origines autorisées, pour qu'une
     * origine acceptée par le CORS ne puisse plus être refusée par la session.
     *
     * @return list<string>
     */
    public static function statefulHosts(): array
    {
        $hosts = self::csv(env('SANCTUM_STATEFUL_DOMAINS', self::isLocal()
            ? 'localhost,localhost:3000,localhost:8000,127.0.0.1,127.0.0.1:3000,127.0.0.1:8000'
            : ''));

        foreach (self::origins() as $origin) {
            $parts = parse_url($origin);
            $hosts[] = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private static function vercelProject(): ?string
    {
        $project = strtolower(trim((string) env('CORS_VERCEL_PROJECT', '')));

        return preg_match('/^[a-z0-9-]+$/', $project) ? $project : null;
    }

    /** @return list<string> */
    private static function csv(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private static function normalize(string $origin): ?string
    {
        $parts = parse_url(rtrim($origin, '/'));
        if (!isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function wwwVariant(string $origin): ?string
    {
        $parts = parse_url($origin);
        $host = $parts['host'];
        if (self::isLocalhost($origin) || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.vercel.app')) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (str_starts_with($host, 'www.')) {
            return $parts['scheme'] . '://' . substr($host, 4) . $port;
        }

        // Seulement pour un domaine nu (waelly.com), pas pour un sous-domaine (app.waelly.com).
        return substr_count($host, '.') === 1 ? $parts['scheme'] . '://www.' . $host . $port : null;
    }

    private static function isLocalhost(string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function isLocal(): bool
    {
        return in_array(env('APP_ENV', 'production'), ['local', 'testing'], true);
    }
}
