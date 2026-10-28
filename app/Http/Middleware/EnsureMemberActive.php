<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Défense en profondeur : un membre suspendu voit toutes ses sessions
 * révoquées au moment de la suspension (voir TeamController::suspend()),
 * donc une requête suivante échoue normalement déjà à l'authentification.
 * Ce middleware couvre le cas où une session resterait valide malgré tout
 * (ex. plusieurs appareils, course entre la révocation et une requête déjà
 * en vol) — toujours appliqué après `auth:sanctum` sur les routes API.
 */
class EnsureMemberActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            abort(403, 'Votre accès a été suspendu. Contactez le responsable.');
        }

        return $next($request);
    }
}
