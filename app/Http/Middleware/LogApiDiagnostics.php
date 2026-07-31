<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — diagnostic des 502/lenteurs en production sur les routes
 * authentifiées (/api/user, /api/notifications/unread-count,
 * /api/onboarding/check, /api/transactions, /api/analytics/*, ...).
 *
 * Dernier middleware avant le dispatch vers le contrôleur (dans ce
 * groupe) : le `mark()` juste avant `$next($request)` correspond donc à
 * l'entrée contrôleur, et celui juste après à la sortie contrôleur.
 * Utilise le RequestTimer partagé (même request_id que les autres
 * providers/middlewares/requêtes SQL) au lieu d'un ID généré localement.
 *
 * Le try/catch ne fait qu'observer — l'exception n'est jamais avalée,
 * juste relancée après logging complet (classe, message, fichier, ligne,
 * trace), donc aucun changement de comportement.
 *
 * À retirer entièrement (fichier + alias 'diag.log' dans bootstrap/app.php
 * + ligne d'enregistrement dans routes/api.php) une fois la cause
 * identifiée.
 */
class LogApiDiagnostics
{
    public function __construct(protected RequestTimer $timer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->timer->mark('LogApiDiagnostics: avant dispatch contrôleur', [
            'method' => $request->method(),
            'path' => $request->path(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'user_id' => Auth::guard('web')->id(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        try {
            // Le segment « Controller » et le tableau final sont désormais
            // mesurés respectivement par ControllerTiming (innermost) et
            // TimingSummary (outermost). Ici on n'observe que le contexte
            // auth (mark ci-dessus) et on relaie l'éventuelle exception.
            return $next($request);
        } catch (\Throwable $e) {
            Log::error('[DIAG] Exception non capturée pendant la requête', [
                'request_id' => $this->timer->id(),
                'path' => $request->path(),
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Pas de total() ici : TimingSummary (outermost, try/finally)
            // rend le tableau une seule fois, y compris sur ce chemin
            // d'exception. On relaie juste l'erreur après l'avoir loguée.
            throw $e;
        }
    }
}
