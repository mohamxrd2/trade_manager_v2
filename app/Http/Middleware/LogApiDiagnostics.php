<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORAIRE — diagnostic des 502 en production sur les routes
 * authentifiées (/api/user, /api/notifications/unread-count,
 * /api/onboarding/check, /api/transactions, /api/analytics/*, ...).
 *
 * Log le début de chaque requête, l'utilisateur authentifié, l'ID de
 * session, le temps d'exécution, et toute exception avec sa stack trace
 * complète AVANT qu'elle ne remonte (elle n'est jamais avalée ici — ce
 * middleware ne fait qu'observer, il relance systématiquement l'exception
 * pour ne rien changer au comportement).
 *
 * À retirer entièrement (fichier + ligne d'enregistrement dans
 * routes/api.php) une fois la cause du 502 identifiée.
 */
class LogApiDiagnostics
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $requestId = uniqid('req_', true);

        Log::info('[DIAG] Requête entrante', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            'user_id' => Auth::guard('web')->id(),
            'has_session_cookie' => $request->hasCookie(config('session.cookie')),
        ]);

        try {
            $response = $next($request);

            Log::info('[DIAG] Requête terminée', [
                'request_id' => $requestId,
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 1),
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::error('[DIAG] Exception non capturée pendant la requête', [
                'request_id' => $requestId,
                'path' => $request->path(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 1),
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
