<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des 502 / lenteurs (7-14s).
 *
 * Middleware le PLUS INTERNE de la pile API (append dans bootstrap/app.php) :
 * son $next() correspond exactement au dispatch du contrôleur (après le
 * route-model-binding), pour TOUTES les routes API. Il isole donc le
 * segment « Controller » de bout en bout, y compris pour les routes
 * publiques (OAuth : c'est ici qu'apparaît la latence de l'appel externe
 * au serveur de token Google).
 *
 * À retirer une fois le diagnostic terminé (fichier + ligne append).
 */
class ControllerTiming
{
    public function __construct(protected RequestTimer $timer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->timer->mark('Controller: entrée');

        $response = $next($request);

        $this->timer->mark('Controller: sortie', [
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
