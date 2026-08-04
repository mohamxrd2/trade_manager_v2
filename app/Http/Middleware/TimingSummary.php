<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des requêtes >6s (voir
 * App\Support\RequestTimer).
 *
 * Middleware le PLUS EXTERNE de la pile API (prepend en 1re position dans
 * bootstrap/app.php) : son post-traitement (après $next) s'exécute donc en
 * DERNIER sur le chemin de sortie, pour TOUTES les routes API (y compris
 * les routes publiques /api/health, /api/login, /api/auth/{provider}/...).
 *
 * C'est lui qui mesure la sérialisation de la réponse (json_encode) et
 * déclenche le rendu du tableau chronologique consolidé.
 *
 * À retirer une fois le diagnostic terminé.
 */
class TimingSummary
{
    public function __construct(protected RequestTimer $timer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = null;

        try {
            $response = $next($request);

            return $response;
        } finally {
            // Rendu garanti pour TOUTE requête, y compris en cas
            // d'exception non gérée où $response reste null.
            $context = [
                'path' => $request->method() . ' /' . ltrim($request->path(), '/'),
                'status' => $response?->getStatusCode() ?? 'EXCEPTION',
            ];

            if ($response !== null) {
                $serStart = microtime(true);
                $content = $response->getContent();
                $context['response_serialize_ms'] = round((microtime(true) - $serStart) * 1000, 1);
                $context['response_bytes'] = $content === false ? 0 : strlen($content);
            }

            $this->timer->total('Requête terminée', $context);
        }
    }
}
