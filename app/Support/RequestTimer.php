<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TEMPORAIRE — instrumentation de diagnostic des requêtes >6s observées en
 * production (PHP-FPM slowlog sur /app/public/index.php), une fois le
 * serveur (Nginx + PHP-FPM) écarté comme goulot d'étranglement.
 *
 * Mesure le temps réel passé dans CHAQUE segment du cycle de vie d'une
 * requête Laravel : bootstrap, chaque middleware, l'authentification
 * Sanctum, chaque requête SQL, le contrôleur, les appels externes (Google,
 * Resend), et la sérialisation de la réponse. Chaque mark() est daté par
 * rapport à LARAVEL_START (défini dans public/index.php, avant même le
 * chargement de l'autoloader) — donc rien de la fenêtre de mesure n'est
 * caché par du code qui s'exécute avant l'instrumentation elle-même.
 *
 * Singleton (voir AppServiceProvider::register()), partagé entre
 * providers, middlewares, DB::listen() et contrôleurs via l'injection de
 * dépendances, avec un request_id unique pour corréler toutes les lignes
 * [TIMING] d'une même requête dans les logs.
 *
 * À retirer entièrement une fois la cause des 6-7s identifiée.
 */
class RequestTimer
{
    /**
     * Catégories correspondant exactement aux 7 mesures demandées.
     */
    public const CAT_BOOTSTRAP = 'BOOTSTRAP';
    public const CAT_MIDDLEWARE = 'MIDDLEWARE';
    public const CAT_SANCTUM = 'SANCTUM';
    public const CAT_SQL = 'SQL';
    public const CAT_CONTROLLER = 'CONTROLLER';
    public const CAT_EXTERNAL_API = 'EXTERNAL_API';
    public const CAT_SERIALIZATION = 'SERIALIZATION';

    private ?string $requestId = null;

    private ?float $startTime = null;

    private ?float $lastMarkTime = null;

    /**
     * @var array<int, array{category: string, label: string, since_last_ms: float, since_start_ms: float, context: array}>
     */
    private array $marks = [];

    /**
     * TEMPORAIRE — détail de chaque requête SQL (voir DB::listen() dans
     * AppServiceProvider::boot()), pour le tableau SQL dédié rendu à la fin
     * de la requête. Séparé de $marks (qui reste, lui, inchangé) pour ne
     * rien modifier du comportement existant.
     *
     * @var array<int, array{sql: string, time_ms: float, file: ?string, line: ?int, controller: ?string}>
     */
    private array $sqlQueries = [];

    /**
     * Démarre le chrono pour la requête courante. Idempotent : si déjà
     * démarré par un provider qui boot avant un autre, ne réinitialise rien.
     */
    public function start(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }

        $this->requestId = (string) Str::uuid();
        $this->startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $this->lastMarkTime = $this->startTime;

        return $this->requestId;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    /**
     * Enregistre un segment de temps, daté et catégorisé. Chaque mark logue
     * immédiatement une ligne [TIMING] ET se bufferise pour le tableau
     * chronologique consolidé produit par total().
     */
    public function mark(string $category, string $label, array $context = []): void
    {
        if ($this->startTime === null) {
            $this->start();
        }

        $now = microtime(true);
        $sinceStart = round(($now - $this->startTime) * 1000, 1);
        $sinceLast = round(($now - $this->lastMarkTime) * 1000, 1);

        Log::info("[TIMING][{$category}] {$label}", array_merge([
            'request_id' => $this->requestId,
            'since_start_ms' => $sinceStart,
            'duration_ms' => $sinceLast,
        ], $context));

        $this->marks[] = [
            'category' => $category,
            'label' => $label,
            'since_last_ms' => $sinceLast,
            'since_start_ms' => $sinceStart,
            'context' => $context,
        ];

        $this->lastMarkTime = $now;
    }

    /**
     * TEMPORAIRE — enregistre le détail d'une requête SQL exécutée pendant
     * la requête HTTP courante (voir DB::listen() dans
     * AppServiceProvider::boot()). N'affecte pas mark()/marks : purement
     * additif, pour le tableau SQL dédié rendu par total().
     */
    public function logSqlQuery(string $sql, float $timeMs, ?string $file, ?int $line, ?string $controller): void
    {
        $this->sqlQueries[] = [
            'sql' => $sql,
            'time_ms' => $timeMs,
            'file' => $file,
            'line' => $line,
            'controller' => $controller,
        ];
    }

    /**
     * Log final avec le temps total ET le tableau chronologique consolidé
     * (une ligne par mark(), dans l'ordre réel d'exécution).
     */
    public function total(string $label = 'Requête terminée', array $context = []): void
    {
        if ($this->startTime === null) {
            return;
        }

        $totalMs = round((microtime(true) - $this->startTime) * 1000, 1);

        Log::info('[TIMING] ' . $label, array_merge([
            'request_id' => $this->requestId,
            'total_ms' => $totalMs,
        ], $context));

        Log::info("\n" . $this->renderTable($totalMs, $context));

        $sqlTable = $this->renderSqlTable();
        if ($sqlTable !== '') {
            Log::info("\n" . $sqlTable);
        }
    }

    private function renderTable(float $totalMs, array $finalContext = []): string
    {
        $width = 66;
        $sep = str_repeat('─', $width + 14);
        $header = sprintf(
            '[TIMING-TABLE] request_id=%s  %s  status=%s',
            $this->requestId,
            $finalContext['path'] ?? '',
            $finalContext['status'] ?? '?'
        );

        $lines = [str_repeat('═', $width + 14), $header, $sep];

        foreach ($this->marks as $m) {
            $rowLabel = sprintf('[%s] %s', $m['category'], $m['label']);
            $lines[] = $this->leaderLine($rowLabel, $m['since_last_ms'], $width);
        }

        if (isset($finalContext['response_serialize_ms'])) {
            $bytes = $finalContext['response_bytes'] ?? '?';
            $lines[] = $this->leaderLine(
                sprintf('[%s] Sérialisation JSON (%s bytes)', self::CAT_SERIALIZATION, $bytes),
                $finalContext['response_serialize_ms'],
                $width
            );
        }

        $lines[] = $sep;
        $lines[] = $this->leaderLine('TOTAL', $totalMs, $width);
        $lines[] = str_repeat('═', $width + 14);

        // Récapitulatif par catégorie : répond directement à "où sont
        // consommées les 6-7 secondes" sans avoir à additionner à la main.
        $byCategory = [];
        foreach ($this->marks as $m) {
            $byCategory[$m['category']] = ($byCategory[$m['category']] ?? 0) + $m['since_last_ms'];
        }
        if (isset($finalContext['response_serialize_ms'])) {
            $byCategory[self::CAT_SERIALIZATION] = ($byCategory[self::CAT_SERIALIZATION] ?? 0) + $finalContext['response_serialize_ms'];
        }
        arsort($byCategory);

        $lines[] = '';
        $lines[] = 'RÉCAPITULATIF PAR CATÉGORIE (trié décroissant) :';
        foreach ($byCategory as $cat => $ms) {
            $pct = $totalMs > 0 ? round(($ms / $totalMs) * 100, 1) : 0;
            $lines[] = $this->leaderLine("  {$cat}", $ms, $width) . "  ({$pct}%)";
        }
        $lines[] = str_repeat('═', $width + 14);

        return implode("\n", $lines);
    }

    /**
     * TEMPORAIRE — tableau dédié détaillant CHAQUE requête SQL de la
     * requête HTTP courante : SQL complet, temps exact (mesuré par Laravel
     * au ras du driver PDO), fichier/ligne applicatif (premier frame de la
     * pile d'appel situé dans app/, donc hors vendor/Illuminate) et
     * contrôleur concerné (route courante au moment de l'exécution de la
     * requête SQL). Numéroté dans l'ordre réel d'exécution, avec le nombre
     * total de requêtes et le temps SQL cumulé.
     */
    private function renderSqlTable(): string
    {
        if (empty($this->sqlQueries)) {
            return '';
        }

        $width = 80;
        $sep = str_repeat('─', $width);

        $lines = [str_repeat('═', $width)];
        $lines[] = sprintf('[SQL-TABLE] request_id=%s — détail des requêtes SQL', $this->requestId);
        $lines[] = $sep;

        $totalMs = 0.0;
        foreach ($this->sqlQueries as $i => $q) {
            $totalMs += $q['time_ms'];

            $lines[] = '';
            $lines[] = ($i + 1) . '.';
            $lines[] = $q['sql'];
            $lines[] = rtrim(rtrim(number_format($q['time_ms'], 1, '.', ''), '0'), '.') . ' ms';
            $lines[] = 'Fichier     : ' . ($q['file'] !== null ? $q['file'] . ($q['line'] !== null ? ':' . $q['line'] : '') : 'non déterminé (hors app/)');
            $lines[] = 'Contrôleur  : ' . ($q['controller'] ?? 'N/A (pas de route résolue à ce stade)');
        }

        $lines[] = '';
        $lines[] = $sep;
        $lines[] = 'Nombre total de requêtes SQL : ' . count($this->sqlQueries);
        $lines[] = 'Temps SQL total : ' . rtrim(rtrim(number_format($totalMs, 1, '.', ''), '0'), '.') . ' ms';
        $lines[] = str_repeat('═', $width);

        return implode("\n", $lines);
    }

    private function leaderLine(string $label, float $ms, int $width): string
    {
        $label = $label . ' ';
        if (strlen($label) > $width) {
            $label = substr($label, 0, $width);
        }
        $dots = str_repeat('.', max(1, $width - strlen($label)));

        return $label . $dots . str_pad(' ' . rtrim(rtrim(number_format($ms, 1, '.', ''), '0'), '.') . ' ms', 12, ' ', STR_PAD_LEFT);
    }
}
