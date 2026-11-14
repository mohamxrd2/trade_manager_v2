<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifiant de requête : repris de l'en-tête X-Request-Id (posé par le
 * front, ou par Nginx, voir docker/nginx.conf.template) s'il est valide,
 * sinon généré. Ajouté au contexte de tous les logs et renvoyé dans
 * X-Request-Id sur TOUTE réponse, erreurs comprises (middleware global) :
 * le front l'affiche dans ses messages d'erreur, on le retrouve dans les
 * logs Render.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->headers->set('X-Request-Id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
