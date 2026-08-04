<?php

namespace App\Http\Middleware;

use App\Support\RequestTimer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware pour gérer les erreurs de connexion à la base de données.
 * 
 * Ce middleware vérifie la disponibilité de la base de données et gère
 * les erreurs de connexion de manière gracieuse en retournant une réponse
 * JSON appropriée au lieu d'une erreur 500 cassée.
 */
class DatabaseConnectionMiddleware
{
    /**
     * Nombre maximum de tentatives de connexion.
     */
    protected int $maxRetries;

    /**
     * Délai entre les tentatives en millisecondes.
     */
    protected int $retryDelay;

    public function __construct()
    {
        $this->maxRetries = (int) config('database.connections.pgsql.retry_attempts', 3);
        $this->retryDelay = (int) config('database.connections.pgsql.retry_delay', 100);
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // TEMPORAIRE — diagnostic des 502 en prod : ce middleware est LE
        // point de blocage suspecté (retry_attempts x DB_TIMEOUT peut
        // bloquer jusqu'à ~15s par requête sur un process mono-worker).
        //
        // TEMPORAIRE — instrumentation ligne à ligne (RequestTimer avant/
        // après CHAQUE instruction) pour localiser précisément l'écart
        // entre le temps mesuré de getPdo() seul (~423ms) et le temps total
        // de ce middleware (~846ms, mesuré en externe par le checkpoint
        // 'MIDDLEWARE,DatabaseConnectionMiddleware terminé' de
        // bootstrap/app.php). Comportement STRICTEMENT inchangé : chaque
        // mark() ne fait que lire l'horloge et écrire un log, sans toucher
        // au flux d'exécution ni aux valeurs retournées.
        $timer = app(RequestTimer::class);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): entrée');

        $start = microtime(true);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): après $start = microtime(true)');

        // Vérifier la connexion DB avec retry automatique
        $ok = $this->checkDatabaseConnectionWithRetry($request->path());
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): après checkDatabaseConnectionWithRetry() (retour dans handle)');

        Log::info('[DIAG] DatabaseConnectionMiddleware terminé', [
            'path' => $request->path(),
            'db_check_ok' => $ok,
            'duration_ms' => round((microtime(true) - $start) * 1000, 1),
        ]);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): après Log::info "DatabaseConnectionMiddleware terminé"');

        if (!$ok) {
            $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): entrée branche !$ok (DB indisponible)');
            $response = $this->databaseUnavailableResponse();
            $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): après databaseUnavailableResponse()');

            return $response;
        }

        // ATTENTION à l'interprétation de ce dernier segment : $next($request)
        // exécute TOUT le reste du pipeline (middlewares suivants, routing,
        // contrôleur) — le temps mesuré ici N'EST PAS du temps interne à ce
        // middleware, mais celui de tout ce qui vient après. Marqué quand
        // même car explicitement demandé ("avant et après CHAQUE
        // instruction"), à exclure du total "DatabaseConnectionMiddleware".
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): avant $next($request)');
        $response = $next($request);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'handle(): après $next($request) [durée = reste du pipeline, PAS ce middleware]');

        return $response;
    }

    /**
     * Vérifie la connexion à la base de données avec retry automatique.
     */
    protected function checkDatabaseConnectionWithRetry(string $path = ''): bool
    {
        // TEMPORAIRE — instrumentation ligne à ligne, voir handle(). Chaque
        // statement du bloc try est encadré d'un mark() pour localiser
        // précisément où passe l'écart entre getPdo() seul (~423ms observé)
        // et le temps total de ce middleware (~846ms observé). Comportement
        // et valeurs de retour strictement inchangés.
        $timer = app(RequestTimer::class);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): entrée');

        $attempts = 0;
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après $attempts = 0');

        while ($attempts < $this->maxRetries) {
            $timer->mark(RequestTimer::CAT_MIDDLEWARE, "checkDatabaseConnectionWithRetry(): entrée boucle while (attempt {$attempts})");

            $attemptStart = microtime(true);
            $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après $attemptStart = microtime(true)');

            try {
                // TEMPORAIRE — instrumentation de diagnostic : déterminer si
                // les ~426ms mesurées sur getPdo() sont payées à CHAQUE
                // requête ou uniquement sur la première requête de chaque
                // worker PHP-FPM.
                //
                // Pas de comparaison "avant/après" en mémoire (via une
                // propriété statique) : PHP réinitialise entièrement l'espace
                // mémoire "userland" — classes, statics, variables — à CHAQUE
                // requête, même quand le même worker/process OS traite les
                // requêtes successives (vérifié empiriquement : un flag
                // statique testé localement restait à false sur 5 requêtes
                // consécutives confirmées comme traitées par le même PID).
                // Seuls des mécanismes bas niveau explicitement conçus pour
                // ça survivent au-delà d'une requête — dont le pool de
                // connexions persistantes de PDO (PDO::ATTR_PERSISTENT),
                // justement ce qu'on cherche à observer ici.
                //
                // On logue donc uniquement les FAITS BRUTS de CETTE requête ;
                // la comparaison entre requêtes se fait a posteriori, en
                // regroupant les lignes de log par php_fpm_worker_pid une
                // fois les 20 requêtes de test effectuées : PID identique +
                // pg_backend_pid_courant identique + getpdo_duration_ms bas
                // => connexion réellement réutilisée par ce worker.
                $workerPid = getmypid();
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après getmypid()');

                // IMPORTANT — candidat direct pour expliquer l'écart avec
                // getPdo() seul : DB::connection() (résolution/config de la
                // connexion Laravel) et ->getPdo() (établissement réel de la
                // connexion PDO) sont deux étapes distinctes, marquées
                // séparément ci-dessous plutôt qu'ensemble comme avant.
                $connection = DB::connection();
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après DB::connection() (résolution, sans connexion PDO)');

                // Tente une requête simple pour vérifier la connexion
                $getPdoStart = microtime(true);
                $pdo = $connection->getPdo();
                $getPdoDurationMs = round((microtime(true) - $getPdoStart) * 1000, 1);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après ->getPdo() (connexion PDO réelle établie)', [
                    'getpdo_duration_ms_mesure_locale' => $getPdoDurationMs,
                ]);

                // IMPORTANT — deuxième appel réseau réel vers Neon (ajouté
                // lors du diagnostic précédent, pas présent dans le code
                // d'origine) : candidat direct pour une partie de l'écart
                // constaté entre getPdo() seul et le total du middleware.
                // pg_backend_pid() : PID du process PostgreSQL/Neon qui gère
                // CETTE connexion physique — fait vérifiable côté serveur,
                // pas une déduction basée sur le temps écoulé.
                $pgBackendPidStart = microtime(true);
                $currentPgBackendPid = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
                $pgBackendPidQueryMs = round((microtime(true) - $pgBackendPidStart) * 1000, 1);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après SELECT pg_backend_pid() [2e appel réseau Neon, ajouté par le diagnostic précédent]', [
                    'pg_backend_pid_query_ms_mesure_locale' => $pgBackendPidQueryMs,
                ]);

                $persistentAttr = $pdo->getAttribute(\PDO::ATTR_PERSISTENT);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après $pdo->getAttribute(PDO::ATTR_PERSISTENT)');

                Log::info('[DB-CONN-DIAG] getPdo() — faits bruts pour cette requête', [
                    'php_fpm_worker_pid' => $workerPid,
                    'getpdo_duration_ms' => $getPdoDurationMs,
                    'pdo_attr_persistent_actif_sur_ce_handle' => $persistentAttr,
                    'pg_backend_pid_courant' => $currentPgBackendPid,
                    'cout_diagnostic_pg_backend_pid_ms' => $pgBackendPidQueryMs,
                ]);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après Log::info "[DB-CONN-DIAG]"');

                Log::info('[DIAG] DatabaseConnectionMiddleware: connexion OK', [
                    'path' => $path,
                    'attempt' => $attempts + 1,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                ]);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après Log::info "connexion OK"');

                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): avant return true');

                return true;
            } catch (\PDOException $e) {
                // TEMPORAIRE — instrumentation, voir plus haut. Branche
                // normalement non empruntée quand Neon répond (le cas
                // ~846ms observé) ; instrumentée quand même comme demandé.
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): entrée catch(PDOException)');

                $attempts++;
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après $attempts++');

                Log::warning("Tentative de connexion DB échouée ({$attempts}/{$this->maxRetries})", [
                    'path' => $path,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après Log::warning (tentative échouée)');

                if ($attempts < $this->maxRetries) {
                    // Attendre avant la prochaine tentative
                    usleep($this->retryDelay * 1000);
                    $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après usleep(retryDelay)');
                }
            } catch (\Exception $e) {
                // TEMPORAIRE — instrumentation, voir plus haut.
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): entrée catch(Exception générique)');

                Log::error("Erreur inattendue lors de la connexion DB", [
                    'path' => $path,
                    'attempt_duration_ms' => round((microtime(true) - $attemptStart) * 1000, 1),
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après Log::error (erreur inattendue)');

                return false;
            }
        }

        // TEMPORAIRE — instrumentation, voir plus haut. Atteint seulement
        // si les $maxRetries tentatives ont toutes échoué.
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): sortie boucle while (toutes tentatives épuisées)');

        Log::error("Base de données indisponible après {$this->maxRetries} tentatives", ['path' => $path]);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'checkDatabaseConnectionWithRetry(): après Log::error (indisponible après N tentatives)');

        return false;
    }

    /**
     * Retourne une réponse JSON propre quand la DB est indisponible.
     */
    protected function databaseUnavailableResponse(): Response
    {
        // TEMPORAIRE — instrumentation, voir handle(). Branche normalement
        // non empruntée dans le cas ~846ms observé (Neon répond), mais
        // instrumentée comme demandé pour couvrir CHAQUE instruction du
        // fichier.
        $timer = app(RequestTimer::class);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'databaseUnavailableResponse(): entrée');

        $response = response()->json([
            'success' => false,
            'error' => 'service_unavailable',
            'message' => 'Le service de base de données est temporairement indisponible. Veuillez réessayer dans quelques instants.',
            'details' => [
                'type' => 'database_connection_error',
                'retry_after' => 30, // Suggérer de réessayer après 30 secondes
            ],
        ], 503, [
            'Retry-After' => 30,
        ]);
        $timer->mark(RequestTimer::CAT_MIDDLEWARE, 'databaseUnavailableResponse(): après response()->json()');

        return $response;
    }
}

