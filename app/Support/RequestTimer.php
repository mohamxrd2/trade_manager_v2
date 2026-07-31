<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TEMPORAIRE — instrumentation de diagnostic des 502 / lenteurs (7-14s) en
 * production. Mesure le temps écoulé (ms) entre chaque étape du cycle de
 * vie d'une requête, avec un `request_id` unique pour corréler toutes les
 * lignes [TIMING] d'une même requête dans les Runtime Logs Render.
 *
 * Enregistré comme singleton (voir AppServiceProvider::register()) pour
 * être partagé entre le service provider, les middlewares, DB::listen()
 * et les contrôleurs sans avoir à le passer explicitement partout.
 *
 * En plus des lignes [TIMING] détaillées, chaque mark est bufferisé pour
 * produire, à la fin de la requête (total()), UN SEUL bloc [TIMING-TABLE]
 * multiligne = le tableau consolidé « où sont perdues les secondes »
 * (une seule entrée de log par request_id, lisible d'un coup d'œil).
 *
 * À retirer entièrement une fois la cause de la lenteur identifiée.
 */
class RequestTimer
{
    private ?string $requestId = null;

    private ?float $startTime = null;

    private ?float $lastMarkTime = null;

    /**
     * Buffer ordonné de tous les marks de la requête, pour reconstituer le
     * tableau consolidé à la fin. Chaque entrée : label, since_last_ms,
     * since_start_ms, context.
     *
     * @var array<int, array{label: string, since_last_ms: float, since_start_ms: float, context: array}>
     */
    private array $marks = [];

    /**
     * Démarre le chrono pour la requête courante. Idempotent : si déjà
     * démarré (par un provider qui boot avant un autre, ou par un
     * middleware), ne réinitialise rien et retourne l'ID existant.
     */
    public function start(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }

        $this->requestId = (string) Str::uuid();
        $this->startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $this->lastMarkTime = $this->startTime;

        Log::info('[TIMING] Entrée requête (PHP démarré)', [
            'request_id' => $this->requestId,
            'php_bootstrap_ms' => defined('LARAVEL_START')
                ? round((microtime(true) - LARAVEL_START) * 1000, 1)
                : null,
        ]);

        return $this->requestId;
    }

    public function id(): ?string
    {
        return $this->requestId;
    }

    /**
     * Log un checkpoint nommé avec le temps écoulé depuis le début de la
     * requête ET depuis le dernier checkpoint (pour isoler la durée de
     * l'étape qui vient de se terminer). Bufferise aussi le mark pour le
     * tableau consolidé.
     */
    public function mark(string $label, array $context = []): void
    {
        if ($this->startTime === null) {
            $this->start();
        }

        $now = microtime(true);
        $sinceStart = round(($now - $this->startTime) * 1000, 1);
        $sinceLast = round(($now - $this->lastMarkTime) * 1000, 1);

        Log::info('[TIMING] ' . $label, array_merge([
            'request_id' => $this->requestId,
            'since_start_ms' => $sinceStart,
            'since_last_mark_ms' => $sinceLast,
        ], $context));

        $this->marks[] = [
            'label' => $label,
            'since_last_ms' => $sinceLast,
            'since_start_ms' => $sinceStart,
            'context' => $context,
        ];

        $this->lastMarkTime = $now;
    }

    /**
     * Log final avec le temps total de la requête ET le tableau consolidé.
     * À appeler juste avant de renvoyer la réponse (dernier middleware
     * avant la sortie).
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

        // Bloc unique lisible d'un coup d'œil dans les Runtime Logs.
        Log::info("\n" . $this->renderTable($totalMs, $context));
    }

    /**
     * Construit le tableau consolidé « où sont perdues les secondes »,
     * dans l'ordre réel d'exécution, à partir du buffer de marks.
     * Une ligne = une étape ; les sous-étapes (getPdo, SELECT 1, SQL) sont
     * indentées sous leur étape parente avec leur durée réelle isolée.
     */
    private function renderTable(float $totalMs, array $finalContext = []): string
    {
        $rows = [];       // [label, ms, indent]
        $bootSubRows = []; // sous-étapes du boot (getPdo/SELECT 1 provider),
                           // émises APRÈS la ligne parente « Bootstrap ».
        $sqlIndex = 0;

        foreach ($this->marks as $m) {
            $label = $m['label'];
            $ctx = $m['context'];
            $sinceLast = $m['since_last_ms'];

            // --- Sous-étapes du boot : mémorisées, émises sous le parent ---
            if (str_contains($label, 'Connexion PDO à Neon établie')) {
                $bootSubRows[] = ['└─ getPdo() — 1re connexion Neon (TCP+TLS+auth)', $ctx['connect_duration_ms'] ?? $sinceLast, 1];
                continue;
            }
            if (str_contains($label, 'SELECT 1 (vérification connexion)')) {
                $bootSubRows[] = ['└─ SELECT 1 (vérif connexion, provider)', $ctx['query_duration_ms'] ?? $sinceLast, 1];
                continue;
            }
            if (str_contains($label, 'DatabaseConnectionMiddleware: connexion OK')) {
                $rows[] = ['└─ getPdo() — middleware, tentative ' . ($ctx['attempt'] ?? '?'), $ctx['attempt_duration_ms'] ?? $sinceLast, 1];
                continue;
            }
            if (str_contains($label, 'tentative') && str_contains($label, 'échouée')) {
                $rows[] = ['└─ getPdo() — TENTATIVE ÉCHOUÉE (timeout/erreur)', $ctx['attempt_duration_ms'] ?? $sinceLast, 1];
                continue;
            }
            if (str_contains($label, 'Requête SQL exécutée')) {
                $sqlIndex++;
                $sql = isset($ctx['sql']) ? $this->shortenSql($ctx['sql']) : '';
                // query_time_ms = temps moteur DB ; since_last = temps mur (inclut latence réseau Neon)
                $rows[] = [
                    "SQL #{$sqlIndex}  {$sql}",
                    $ctx['query_time_ms'] ?? $sinceLast,
                    0,
                ];
                continue;
            }

            // --- Étapes principales : since_last = durée de l'étape ---
            $friendly = $this->friendlyLabel($label);
            if ($friendly === null) {
                continue; // mark de contexte (ex : boot avant/après vérif) déjà couvert ailleurs
            }
            $rows[] = [$friendly, $sinceLast, 0];

            // Juste après la ligne « Bootstrap », rattacher ses sous-étapes.
            if (str_contains($friendly, 'Bootstrap') && $bootSubRows !== []) {
                foreach ($bootSubRows as $sub) {
                    $rows[] = $sub;
                }
                $bootSubRows = [];
            }
        }

        // Filet de sécurité : si le parent « Bootstrap » n'a pas été vu
        // (ex : pipeline_start absent), on émet quand même les sous-étapes.
        foreach ($bootSubRows as $sub) {
            $rows[] = $sub;
        }

        // Ligne Response (#10) si fournie par LogApiDiagnostics
        if (isset($finalContext['response_serialize_ms'])) {
            $bytes = $finalContext['response_bytes'] ?? '?';
            $rows[] = ["Response (sérialisation JSON, {$bytes} bytes)", $finalContext['response_serialize_ms'], 0];
        }

        // --- Rendu ASCII aligné avec leaders pointillés ---
        $width = 62; // largeur colonne label (avant les ms)
        $sep = str_repeat('─', $width + 12);
        $header = sprintf(
            '[TIMING-TABLE] request_id=%s  %s  status=%s',
            $this->requestId,
            $finalContext['path'] ?? '',
            $finalContext['status'] ?? '?'
        );

        $lines = [str_repeat('═', $width + 12), $header, $sep];
        foreach ($rows as [$label, $ms, $indent]) {
            $lines[] = $this->leaderLine($label, $ms, $width, $indent);
        }
        $lines[] = $sep;
        $lines[] = $this->leaderLine('TOTAL', $totalMs, $width, 0);
        $lines[] = str_repeat('═', $width + 12);

        return implode("\n", $lines);
    }

    /**
     * Mappe le label technique d'un mark vers un nom lisible pour le
     * tableau, ou null si le mark ne doit pas apparaître comme ligne
     * principale.
     */
    private function friendlyLabel(string $label): ?string
    {
        return match (true) {
            str_contains($label, 'Checkpoint: pipeline_start')            => 'Bootstrap PHP + providers (avant 1er middleware)',
            str_contains($label, 'Checkpoint: after_force_json')          => 'ForceJsonResponse',
            str_contains($label, 'Checkpoint: after_cors')                => 'HandleCors',
            str_contains($label, 'DatabaseConnectionMiddleware: début')   => null,
            str_contains($label, 'DatabaseConnectionMiddleware: fin')     => 'DatabaseConnectionMiddleware (total, dont getPdo)',
            str_contains($label, 'Checkpoint: after_db_connection_check') => null, // redondant avec le "fin" ci-dessus
            str_contains($label, 'Checkpoint: after_sanctum_stateful')    => 'EnsureFrontendRequestsAreStateful',
            str_contains($label, 'avant dispatch contrôleur')             => 'auth:sanctum',
            str_contains($label, 'Controller: entrée')                    => 'Résolution route + bindings',
            str_contains($label, 'Controller: sortie')                    => 'Controller',
            default => null,
        };
    }

    private function shortenSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        return strlen($sql) > 34 ? substr($sql, 0, 31) . '...' : $sql;
    }

    private function leaderLine(string $label, float $ms, int $width, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $label = $pad . $label . ' ';
        if (strlen($label) > $width) {
            $label = substr($label, 0, $width);
        }
        $dots = str_repeat('.', max(1, $width - strlen($label)));
        return $label . $dots . str_pad(' ' . rtrim(rtrim(number_format($ms, 1, '.', ''), '0'), '.') . ' ms', 12, ' ', STR_PAD_LEFT);
    }
}
