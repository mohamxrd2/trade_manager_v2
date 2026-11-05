<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Traces légères, sans service externe : toute requête API plus lente que
 * SLOW_REQUEST_MS (800 ms par défaut) est journalisée (« Slow request »)
 * avec sa durée, son nombre de requêtes SQL, leur temps cumulé et la plus
 * lente — de quoi trouver la requête à optimiser dans les logs Render.
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $queries = 0;
        $sqlMs = 0.0;
        $slowest = ['ms' => 0.0, 'sql' => null];

        DB::listen(function (QueryExecuted $query) use (&$queries, &$sqlMs, &$slowest) {
            $queries++;
            $sqlMs += $query->time;
            if ($query->time > $slowest['ms']) {
                $slowest = ['ms' => $query->time, 'sql' => mb_substr($query->sql, 0, 300)];
            }
        });

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
        if ($durationMs >= (int) config('app.slow_request_ms', 800)) {
            Log::warning('Slow request', [
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round($durationMs),
                'queries' => $queries,
                'sql_ms' => (int) round($sqlMs),
                'slowest_query_ms' => (int) round($slowest['ms']),
                'slowest_query' => $slowest['sql'],
            ]);
        }

        return $response;
    }
}
