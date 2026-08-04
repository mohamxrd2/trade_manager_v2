<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des requêtes >6s (voir
 * App\Support\RequestTimer). Middleware générique et sans effet de bord, à
 * intercaler entre chaque middleware de la pile pour mesurer, par
 * déduction entre deux checkpoints consécutifs, le temps pris par le
 * middleware placé entre les deux — y compris les middlewares tiers
 * (HandleCors, EnsureFrontendRequestsAreStateful, auth:sanctum) qu'on ne
 * peut pas instrumenter directement sans modifier le code du
 * framework/de Sanctum.
 *
 * Usage : TimingCheckpoint::class.':<catégorie>,<label>' dans un tableau
 * de middleware (syntaxe standard des paramètres de middleware Laravel).
 * Catégorie doit être une des constantes RequestTimer::CAT_*.
 *
 * À retirer une fois le diagnostic terminé.
 */
class TimingCheckpoint
{
    public function handle(Request $request, Closure $next, string $category, string $label): Response
    {
        app(RequestTimer::class)->mark($category, $label);

        return $next($request);
    }
}
