<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des requêtes >6s (voir
 * App\Support\RequestTimer). Contrairement à TimingCheckpoint (deux points
 * distincts dans le tableau de middleware), le contrôleur n'est PAS un
 * élément de ce tableau : il est invoqué en interne par le routeur une
 * fois TOUS les middlewares passés — il n'y a donc pas d'entrée "après le
 * contrôleur" possible dans un tableau de middleware. Cette classe
 * encadre elle-même $next() (before puis after) pour isoler précisément
 * cette durée.
 *
 * DOIT être placée en DERNIER dans le tableau de middleware d'un groupe
 * de routes (voir routes/api.php) pour que $next() corresponde
 * exactement à la résolution des bindings + l'exécution du contrôleur,
 * sans qu'aucun autre middleware ne s'intercale entre les deux marks.
 *
 * À retirer une fois le diagnostic terminé.
 */
class ControllerTiming
{
    public function __construct(protected RequestTimer $timer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->timer->mark(RequestTimer::CAT_CONTROLLER, 'Avant dispatch (résolution route + bindings)');

        $response = $next($request);

        $this->timer->mark(RequestTimer::CAT_CONTROLLER, 'Exécution contrôleur terminée', [
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}
