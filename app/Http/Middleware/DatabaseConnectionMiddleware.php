<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ancien point d'entrée pour la vérification préventive de la connexion DB.
 *
 * Le pré-check explicite (DB::connection()->getPdo() avant chaque requête)
 * a été retiré : il coûtait ~430ms sur CHAQUE requête pour ne faire que ce
 * que Laravel fait déjà nativement — la connexion PDO est résolue de façon
 * paresseuse, uniquement quand une vraie requête SQL est exécutée (session
 * 'database', Eloquent, Sanctum...).
 *
 * Si cette connexion lazy échoue, les handlers PDOException/QueryException
 * de bootstrap/app.php interceptent l'erreur et renvoient le même JSON 503
 * (avec Retry-After) que ce middleware renvoyait auparavant — aucune perte
 * de comportement côté client.
 *
 * Conservé dans le pipeline (bootstrap/app.php) uniquement comme point
 * d'extension explicite si un besoin futur de logique par-requête liée à la
 * DB apparaît ; ne fait actuellement rien.
 */
class DatabaseConnectionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
