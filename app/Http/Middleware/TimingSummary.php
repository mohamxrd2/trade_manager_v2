<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des 502 / lenteurs (7-14s).
 *
 * Middleware le PLUS EXTERNE de la pile API (prepend en 1re position dans
 * bootstrap/app.php) : son post-traitement (après $next) s'exécute donc en
 * DERNIER sur le chemin de sortie, pour TOUTES les routes API (y compris
 * les routes publiques /api/health, /api/login, /api/auth/{provider}/...
 * qui n'ont pas le middleware 'diag.log').
 *
 * C'est lui qui :
 *  - mesure #10 : la sérialisation de la réponse (json_encode) + sa taille,
 *  - déclenche total() → le tableau consolidé [TIMING-TABLE].
 *
 * À retirer une fois le diagnostic terminé (fichier + ligne prepend).
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
            // Rendu du tableau garanti pour TOUTE requête, y compris en cas
            // d'exception non gérée (cause fréquente des 502) où $response
            // reste null.
            $context = [
                'path' => $request->method() . ' /' . ltrim($request->path(), '/'),
                'status' => $response?->getStatusCode() ?? 'EXCEPTION',
            ];

            if ($response !== null) {
                // #10 — Sérialisation de la réponse : force le rendu du
                // corps (json_encode pour une JsonResponse) et mesure durée
                // + taille. Un gros payload explique une partie du temps mur.
                $serStart = microtime(true);
                $content = $response->getContent();
                $context['response_serialize_ms'] = round((microtime(true) - $serStart) * 1000, 1);
                $context['response_bytes'] = $content === false ? 0 : strlen($content);
            }

            $this->timer->total('Requête terminée', $context);
        }
    }
}
