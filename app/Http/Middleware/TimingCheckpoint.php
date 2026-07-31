<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — instrumentation de diagnostic des 502/lenteurs en prod.
 *
 * Middleware générique et sans effet de bord, à intercaler entre chaque
 * middleware de la pile globale (voir bootstrap/app.php) pour mesurer,
 * par déduction entre deux checkpoints consécutifs, le temps pris par le
 * middleware placé entre les deux — y compris les middlewares tiers
 * (HandleCors, EnsureFrontendRequestsAreStateful) qu'on ne peut pas
 * instrumenter directement sans modifier le code du framework/de Sanctum.
 *
 * Usage : TimingCheckpoint::class . ':<label>' dans un tableau de
 * middleware (syntaxe standard des paramètres de middleware Laravel).
 */
class TimingCheckpoint
{
    public function handle(Request $request, Closure $next, string $label): Response
    {
        app(RequestTimer::class)->mark("Checkpoint: {$label}");

        return $next($request);
    }
}
