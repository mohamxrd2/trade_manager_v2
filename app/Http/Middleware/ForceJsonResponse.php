<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour forcer les réponses JSON sur les routes API.
 * 
 * Ce middleware s'assure que:
 * - Toutes les requêtes API sont traitées comme attendant du JSON
 * - Les réponses sont toujours au format JSON
 * - Les erreurs sont également retournées en JSON
 */
class ForceJsonResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Forcer le header Accept à application/json pour que Laravel
        // sache que le client attend une réponse JSON
        $request->headers->set('Accept', 'application/json');

        $response = $next($request);

        // S'assurer que le Content-Type de la réponse est JSON
        // sauf pour les réponses de téléchargement de fichiers
        if (!$response->headers->has('Content-Disposition')) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}

