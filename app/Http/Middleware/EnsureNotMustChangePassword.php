<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quand `must_change_password=true` (code à 6 chiffres jamais remplacé),
 * TOUTES les routes renvoient 403 SAUF celle nommée `team.change-password`
 * (et `logout`/`user`, pour que le frontend puisse encore afficher l'état
 * et déconnecter) — voir routes/api.php.
 */
class EnsureNotMustChangePassword
{
    private const ALWAYS_ALLOWED = ['team.change-password', 'logout', 'user'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_ALLOWED, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code' => 'MUST_CHANGE_PASSWORD',
            'message' => 'Vous devez changer votre code avant de continuer.',
        ], 403);
    }
}
