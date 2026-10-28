<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `->middleware('permission:sales.create')` sur une route — s'appuie sur le
 * MÊME point de vérification que partout ailleurs (`$user->can()`, voir
 * Gate::before dans AppServiceProvider), donc jamais de double logique à
 * maintenir entre le middleware de route et les vérifications inline dans
 * les contrôleurs.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user || !$user->can($permission)) {
            abort(403, "Permission requise : {$permission}");
        }

        return $next($request);
    }
}
