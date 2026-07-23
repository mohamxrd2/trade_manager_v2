<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Routes de diagnostic (santé du service, connexion DB, CORS).
 *
 * Extraites de routes/api.php : les routes définies via Closure ne sont pas
 * supportées par `php artisan route:cache` (obligatoire en production sur
 * Render). Ces méthodes remplacent les anciennes closures à l'identique.
 */
class HealthController extends Controller
{
    /**
     * GET /api/health — vérifie que le serveur et la base de données répondent.
     */
    public function check(): JsonResponse
    {
        $dbConnected = false;
        $dbError = null;

        try {
            DB::connection()->getPdo();
            $dbConnected = true;
        } catch (\Exception $e) {
            $dbError = $e->getMessage();
        }

        return response()->json([
            'success' => true,
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'api' => true,
                'database' => $dbConnected,
            ],
            'database_error' => $dbError,
        ]);
    }

    /**
     * GET /api/test-db — hors production uniquement (voir routes/api.php).
     */
    public function testDb(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'success' => true,
                'message' => '✅ Connexion à la base de données réussie!',
                'database' => DB::connection()->getDatabaseName(),
                'driver' => DB::connection()->getDriverName(),
                'status' => 'connected'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '❌ Erreur de connexion à la base de données',
                'error' => $e->getMessage(),
                'status' => 'disconnected'
            ], 500);
        }
    }

    /**
     * GET /api/test-cors — hors production uniquement (voir routes/api.php).
     */
    public function testCors(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '✅ CORS fonctionne correctement!',
            'origin' => $request->header('Origin', 'N/A'),
            'method' => $request->method(),
            'headers' => [
                'received' => [
                    'origin' => $request->header('Origin'),
                    'content-type' => $request->header('Content-Type'),
                    'authorization' => $request->header('Authorization') ? 'Present' : 'Not present',
                ],
            ],
            'cors_config' => [
                'allowed_origins' => config('cors.allowed_origins'),
                'supports_credentials' => config('cors.supports_credentials'),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/test-cors — hors production uniquement (voir routes/api.php).
     */
    public function testCorsPost(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '✅ CORS POST fonctionne correctement!',
            'origin' => $request->header('Origin', 'N/A'),
            'method' => $request->method(),
            'body_received' => $request->all(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
